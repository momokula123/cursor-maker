<?php
/**
 * 鼠标光标生成器 (Cursor Maker · 光标工坊)
 * - 官方 BYOP OAuth 登录（Bring Your Own Pollen，PKCE S256 授权码流程）
 * - 通过 Pollinations 作图 API 用 flux / zimage 生成光标样式图片
 * - 导出为 Windows .cur 光标文件（可设置热点/尺寸/透明背景）
 *
 * 部署要求：PHP 8+（curl / gd / session / openssl 扩展），Web 根目录放本文件即可。
 * 配置：见下方 OAUTH_CLIENT_ID，需在 https://enter.pollinations.ai/keys 创建 App Key 并填写。
 */

declare(strict_types=1);

// 版本号：用于线上校验部署是否最新（以 HTML 注释输出，不污染页面）
const APP_VERSION = '2.5.0';

// 生产安全：错误写日志而非输出到页面，避免污染 JSON/二进制响应
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// session 目录兜底：默认会话目录不存在/不可写时改用系统临时目录（保证开箱即用）
$__sess = @session_save_path();
if ($__sess === '' || $__sess === false || !is_dir($__sess) || !is_writable($__sess)) {
    $__alt = sys_get_temp_dir() . '/php_sess_' . md5(__DIR__);
    @mkdir($__alt, 0777, true);
    if (is_dir($__alt) && is_writable($__alt)) {
        session_save_path($__alt);
    }
}
unset($__sess, $__alt);

session_start();

/* ======================= 配置 ======================= */
// 机密配置（OAuth 客户端 ID / 开发者密钥 / 邀请码哈希）从 config.php 加载，
// 该文件已在 .gitignore 中排除，不会随仓库泄露。模板见 config.example.php。
require __DIR__ . '/index_assets/config.php';
if (!defined('OAUTH_CLIENT_ID')) {
    exit('未配置 OAUTH_CLIENT_ID。请复制 config.example.php 为 config.php 并填入你的 App Key。');
}

// 回调地址：默认自动取当前站点根路径（如 https://hk.1r.gs/）。
// 若站点需 /index.php 才命中，可改为 '/index.php' 或完整地址，留空为自动。
const REDIRECT_URI_OVERRIDE = '';

const OAUTH_AUTHORIZE = 'https://enter.pollinations.ai/authorize';
const OAUTH_TOKEN     = 'https://enter.pollinations.ai/api/oauth/token';
const OAUTH_USERINFO  = 'https://enter.pollinations.ai/api/oauth/userinfo';
const GEN_IMAGE_URL   = 'https://gen.pollinations.ai/image/';

// 部分环境（如宝塔默认 php.ini）未配置 CA 证书，https 校验会失败。
// 开启后仅在系统证书校验失败时降级为不校验证书；生产环境若已配好 CA 可改为 false。
const ALLOW_INSECURE_SSL_FALLBACK = true;

const BALANCE_URL = 'https://gen.pollinations.ai/account/balance';

// 免费生图次数（按账号落地统计，非会话）
const FREE_CREDITS = 5;
// 统计口径：每自然月每个账号最多免费 FREE_CREDITS 次（月末自动重置）
const QUOTA_FILE = __DIR__ . '/index_assets/data/quota.json';

// 免登录邀请码（游客）免费生成总上限：全站累计最多 GUEST_LIMIT 次，达到后拒绝（不显示给用户）
const GUEST_LIMIT = 50;
const GUEST_QUOTA_FILE = __DIR__ . '/index_assets/data/guest_count.json';

/* ======================= 工具函数 ======================= */

function redirectUri(): string {
    if (REDIRECT_URI_OVERRIDE !== '') {
        return str_starts_with(REDIRECT_URI_OVERRIDE, 'http') ? REDIRECT_URI_OVERRIDE
            : (scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . REDIRECT_URI_OVERRIDE);
    }
    // 自动跟随部署目录（根目录或子目录均可），保证与 App Key 里登记的 Redirect URI 一致
    $dir = rtrim(str_replace('\\', '/', (string)dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function scheme(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    return $https ? 'https' : 'http';
}

function base64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** 简单 cURL 请求，返回 [status, body]。证书缺失时按配置降级重试。 */
function httpRequest(string $url, array $headers = [], ?string $post = null): array {
    $run = function (bool $verify) use ($url, $headers, $post): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST]         = true;
            $opts[CURLOPT_POSTFIELDS]   = $post;
        }
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        curl_close($ch);
        return [$status, $body === false ? null : (string)$body, $errno, $err];
    };

    [$status, $body, $errno, $err] = $run(true);
    if ($body === null && ALLOW_INSECURE_SSL_FALLBACK && in_array($errno, [60, 77], true)) {
        [$status, $body] = $run(false);
    }
    if ($body === null) {
        return [$status, '{"error":"cURL(' . $errno . '): ' . addslashes($err) . '"}'];
    }
    return [$status, $body];
}

function getToken(): ?string {
    return !empty($_SESSION['token']) ? (string)$_SESSION['token'] : null;
}

function jsonOut(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function flash(string $msg): void {
    $_SESSION['flash'] = $msg;
}

function takeFlash(): string {
    $f = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $f;
}

/** 从接口错误 JSON 里尽量取出可读的 message（支持嵌套 error/message/detail） */
function apiErrorMessage(array $j, int $status): string {
    foreach (['error', 'message', 'detail'] as $k) {
        $v = $j[$k] ?? null;
        if (is_string($v) && $v !== '') return $v;
        if (is_array($v)) {
            foreach (['message', 'error', 'detail', 'description'] as $kk) {
                if (is_string($v[$kk] ?? null) && $v[$kk] !== '') return (string)$v[$kk];
            }
        }
    }
    return 'HTTP ' . $status;
}

/** 从请求返回的图片字节里识别 / 生成 PNG（用于预览与 .cur） */
function toPngBytes(string $data): ?string {
    $im = @imagecreatefromstring($data);
    if (!$im) return null;
    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);
    return $png === false ? null : $png;
}

/** 组装 Windows .cur（PNG 压缩，支持 alpha，Win Vista+ 原生支持） */
function buildCur(int $size, string $png, int $hx, int $hy): string {
    $header = pack('vvv', 0, 2, 1);                                   // ICONDIR: 保留/类型(2=光标)/数量
    $entry  = pack('CCCCvvVV', $size & 0xFF, $size & 0xFF, 0, 0,      // ICONDIRENTRY
                   $hx & 0xFFFF, $hy & 0xFFFF, strlen($png), 22);     // 尺寸/热点/长度/偏移
    return $header . $entry . $png;
}

/** 四角连通区域背景抠除（带颜色容差），适合把纯色/渐变背景变透明 */
function makeBackgroundTransparent($im, int $tolerance = 60): void {
    $w = imagesx($im); $h = imagesy($im);
    imagealphablending($im, false);
    $visited = array_fill(0, $w * $h, false);
    $queue   = [];
    foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$sx, $sy]) {
        $si = $sy * $w + $sx;
        if ($visited[$si]) continue;
        $c = imagecolorat($im, $sx, $sy);
        $br = ($c >> 16) & 0xFF; $bg = ($c >> 8) & 0xFF; $bb = $c & 0xFF;
        $visited[$si] = true;
        $queue[] = [$sx, $sy, $br, $bg, $bb];
    }
    while ($queue) {
        [$x, $y, $br, $bg, $bb] = array_pop($queue);
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
        if (abs($r - $br) + abs($g - $bg) + abs($b - $bb) <= $tolerance) {
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, 127));
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx; $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) continue;
                $ni = $ny * $w + $nx;
                if (!$visited[$ni]) { $visited[$ni] = true; $queue[] = [$nx, $ny, $br, $bg, $bb]; }
            }
        }
    }
    imagesavealpha($im, true);
}

/* ============ 免费配额（按账号落地统计，文件存储） ============ */

/** 当前账号的稳定身份：优先用 Pollinations 用户 ID（sub），取不到则用令牌哈希 */
function currentUserId(): string {
    if (!getToken()) return 'anon';
    if (!empty($_SESSION['uid'])) return (string)$_SESSION['uid'];
    [$s, $b] = httpRequest(OAUTH_USERINFO, ['Authorization: Bearer ' . getToken()]);
    $id = '';
    if ($s === 200) {
        $j = json_decode($b, true) ?: [];
        $id = (string)($j['sub'] ?? $j['preferred_username'] ?? $j['githubUsername'] ?? '');
    }
    if ($id === '') {
        $id = 'k_' . substr(hash('sha256', getToken()), 0, 24); // 令牌哈希兜底
    }
    $_SESSION['uid'] = $id;
    return $id;
}

function quotaData(): array {
    $data = [];
    if (is_file(QUOTA_FILE)) {
        $raw = @file_get_contents(QUOTA_FILE);
        $j = json_decode((string)$raw, true);
        if (is_array($j)) $data = $j;
    }
    return $data;
}

/** 该账号在当前自然月已用免费次数 */
function freeUsedInWindow(string $uid): int {
    $monthStart = strtotime(date('Y-m-01 00:00:00')); // 本月 1 号 0 点
    $n = 0;
    foreach (quotaData()[$uid] ?? [] as $t) {
        if ((int)$t >= $monthStart) $n++;
    }
    return $n;
}

/** 该账号剩余免费次数 */
function freeLeft(string $uid): int {
    return max(0, FREE_CREDITS - freeUsedInWindow($uid));
}

/** 记录一次免费使用（当前自然月，加文件锁防并发） */
function recordFreeUse(string $uid): void {
    $data = quotaData();
    $monthStart = strtotime(date('Y-m-01 00:00:00'));
    $ts = array_values(array_filter($data[$uid] ?? [], fn($t) => (int)$t >= $monthStart));
    $ts[] = time();
    $data[$uid] = $ts;
    @mkdir(dirname(QUOTA_FILE), 0777, true);
    $fp = @fopen(QUOTA_FILE, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, (string)json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** 当前邀请码（游客）累计已用免费次数 */
function guestUsed(): int {
    $n = 0;
    $raw = @file_get_contents(GUEST_QUOTA_FILE);
    if ($raw !== false) { $j = json_decode((string)$raw, true); if (is_int($j)) $n = $j; }
    return $n;
}

/** 尝试为游客占一次次数；未超限则 +1 并返回 true，已超限返回 false */
function guestTryUse(): bool {
    @mkdir(dirname(GUEST_QUOTA_FILE), 0777, true);
    $fp = @fopen(GUEST_QUOTA_FILE, 'c+');
    if (!$fp) return true; // 无法写入时放行（不阻塞正常用户）
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $n = 0;
    if ($raw !== false) { $j = json_decode($raw, true); if (is_int($j)) $n = $j; }
    if ($n >= GUEST_LIMIT) { flock($fp, LOCK_UN); fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)json_encode($n + 1));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/* ======================= 路由 ======================= */

$action = $_GET['action'] ?? '';

/* ---- 1. 发起登录（PKCE） ---- */
if ($action === 'login') {
    if (OAUTH_CLIENT_ID === 'pk_your_key_here') {
        http_response_code(500);
        exit('未配置 OAUTH_CLIENT_ID。请先在 enter.pollinations.ai/keys 创建 App Key 并填入 index.php 配置。');
    }
    $verifier   = base64url(random_bytes(32));
    $challenge  = base64url(hash('sha256', $verifier, true));
    $_SESSION['pkce_verifier'] = $verifier;
    $_SESSION['oauth_state']   = bin2hex(random_bytes(16));
    $params = http_build_query([
        'response_type'         => 'code',
        'client_id'             => OAUTH_CLIENT_ID,
        'redirect_uri'          => redirectUri(),
        'scope'                 => 'profile usage',
        'models'                => 'flux,zimage',
        'state'                 => $_SESSION['oauth_state'],
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    header('Location: ' . OAUTH_AUTHORIZE . '?' . $params);
    exit;
}

/* ---- 2. 回调：换令牌（授权后跳回地址形如 ?code=...&state=...，无 action 参数） ---- */
if ($action === 'callback' || isset($_GET['code']) || isset($_GET['error'])) {
    $error = $_GET['error'] ?? '';
    if ($error !== '') {
        flash('授权被拒绝：' . htmlspecialchars((string)$error));
        header('Location: ' . redirectUri());
        exit;
    }
    $code  = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    if ($code === '' || $state === '' || empty($_SESSION['oauth_state'])
        || !hash_equals($_SESSION['oauth_state'], (string)$state)) {
        flash('登录校验失败（state 不匹配），请重试。');
        header('Location: ' . redirectUri());
        exit;
    }
    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => OAUTH_CLIENT_ID,
        'redirect_uri'  => redirectUri(),
        'code_verifier' => $_SESSION['pkce_verifier'] ?? '',
    ]);
    [$status, $resp] = httpRequest(OAUTH_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], $body);
    $json = json_decode($resp, true) ?: [];
    if ($status === 200 && !empty($json['access_token'])) {
        $_SESSION['token'] = (string)$json['access_token'];
        $_SESSION['mode']  = 'oauth';
        unset($_SESSION['uid']); // 重新获取账号身份（配额按账号统计）
        flash('✅ 登录成功！每月有 ' . FREE_CREDITS . ' 次免费生成，开始制作光标吧！');
    } else {
        flash('令牌交换失败：' . htmlspecialchars((string)($json['error'] ?? $resp)));
    }
    unset($_SESSION['oauth_state'], $_SESSION['pkce_verifier']);
    header('Location: ' . redirectUri());
    exit;
}

/* ---- 退出 ---- */
if ($action === 'logout') {
    unset($_SESSION['token'], $_SESSION['mode']);
    flash('已退出登录。');
    header('Location: ' . redirectUri());
    exit;
}

/* ---- 邀请码校验（免登录游客） ---- */
if ($action === 'invite') {
    $in  = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $key = trim((string)($in['key'] ?? ''));
    if ($key === '') { jsonOut(['ok' => false, 'error' => '请输入邀请码']); }
    if (hash_equals(GUEST_TOKEN_HASH, hash('sha256', $key))) {
        $_SESSION['guest_ok'] = true;
        jsonOut(['ok' => true]);
    }
    jsonOut(['ok' => false, 'error' => '邀请码无效']);
}

/* ---- 生成图片（需登录或凭邀请码的游客） ---- */
if ($action === 'generate') {
    $token   = getToken();
    $isGuest = !empty($_SESSION['guest_ok']); // 凭邀请码的游客（或登录后也输入邀请码）：无限生成
    if ($token === null && !$isGuest) { jsonOut(['ok' => false, 'error' => '未登录']); }

    $in      = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $prompt  = trim((string)($in['prompt'] ?? ''));
    $model   = (($in['model'] ?? 'zimage') === 'flux') ? 'flux' : 'zimage';
    $genSize = 512; // 生成分辨率（再缩放到光标尺寸，保证清晰）

    if ($prompt === '') { jsonOut(['ok' => false, 'error' => '请输入光标样式描述']); }

    // 游客（凭邀请码）：直接用开发者 key，不扣账号配额；但全站邀请码免费生成有总次数上限
    if ($isGuest) {
        if (guestUsed() >= GUEST_LIMIT) {
            jsonOut(['ok' => false, 'error' => '免登录邀请码次数已用完，请登录后使用，或联系站长获取新邀请码。']);
        }
        $useKey    = DEVELOPER_KEY;
        $paySource = 'guest';
    } else {
        // 登录用户：优先用账号免费配额（开发者 key 支付）；配额用完则查询用户余额
        $uid      = currentUserId();
        $freeLeft = freeLeft($uid);
        if ($freeLeft > 0) {
            $useKey    = DEVELOPER_KEY;
            $paySource = 'free';
        } else {
            [$bs, $bb] = httpRequest(BALANCE_URL, ['Authorization: Bearer ' . $token]);
            $bal = 0.0;
            if ($bs === 200) { $bj = json_decode($bb, true) ?: []; $bal = (float)($bj['balance'] ?? 0); }
            if ($bal <= 0) {
                jsonOut([
                    'ok'       => false,
                    'error'    => '免费次数已用完，且你的花粉余额为 0。请充值后继续，或重新登录获取免费次数。',
                    'recharge' => true,
                ]);
            }
            $useKey    = $token;
            $paySource = 'user';
        }
    }

    $url = GEN_IMAGE_URL . rawurlencode($prompt)
         . '?model=' . $model . '&width=' . $genSize . '&height=' . $genSize;
    [$status, $body] = httpRequest($url, ['Authorization: Bearer ' . $useKey]);

    if ($status !== 200) {
        $j = json_decode($body, true) ?: [];
        $msg = apiErrorMessage($j, $status);
        $recharge = false;
        if ($status === 402) {
            $msg = ($paySource === 'free' ? '开发者免费额度不足（开发者 key 余额耗尽）：' : '余额不足（HTTP 402）：')
                 . $msg;
            $recharge = true;
        }
        jsonOut(['ok' => false, 'error' => $msg, 'recharge' => $recharge]);
    }

    $png = toPngBytes($body);
    if ($png === null) {
        $j = json_decode($body, true) ?: [];
        jsonOut(['ok' => false, 'error' => apiErrorMessage($j, 200)]);
    }

    if (isset($uid) && $paySource === 'free') {
        recordFreeUse($uid);
    }
    if ($paySource === 'guest') {
        guestTryUse(); // 邀请码总次数 +1（不展示剩余）
    }
    jsonOut([
        'ok'       => true,
        'image'    => base64_encode($png),
        'model'    => $model,
        'pay'      => $paySource,
        'freeLeft' => isset($uid) ? freeLeft($uid) : -1,
    ]);
}

/* ---- 导出 .cur ---- */
if ($action === 'cur') {
    $in     = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $b64    = (string)($in['image'] ?? '');
    $size   = max(16, min(128, (int)($in['size'] ?? 48)));
    $hx     = max(0, min($size - 1, (int)($in['hotspot_x'] ?? (int)floor($size / 2))));
    $hy     = max(0, min($size - 1, (int)($in['hotspot_y'] ?? (int)floor($size / 2))));
    $transparent = !empty($in['transparent']);

    $data = base64_decode($b64);
    if ($data === false || strlen($data) > 8 * 1024 * 1024) { http_response_code(400); exit('bad image'); }
    $src = @imagecreatefromstring($data);
    if (!$src) { http_response_code(400); exit('bad image'); }

    // 可选：四角连通区域背景抠除（带容差，处理纯色/渐变背景）
    if ($transparent) {
        makeBackgroundTransparent($src);
    }

    // 原尺寸绘制：热点坐标与预览所点位置完全对应，不做缩放换算
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    $bg = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $bg);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    imagesavealpha($dst, true);

    ob_start();
    imagepng($dst);
    $png = ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    if ($png === false) { http_response_code(500); exit('encode fail'); }

    $cur = buildCur($size, $png, $hx, $hy);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="cursor_' . $size . 'x' . $size . '_' . $hx . 'x' . $hy . '.cur"');
    header('Content-Length: ' . strlen($cur));
    echo $cur;
    exit;
}

/* ======================= 页面 ======================= */

// 语言：按访问者 IP 归属地自动切换（中国大陆→中文，其他→英文）。结果存 session 缓存，避免每次请求都调 GeoIP。
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = trim((string)($_SERVER[$k] ?? ''));
        if ($v !== '') {
            $first = explode(',', $v)[0];
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
    }
    return '0.0.0.0';
}
function detectLang(): string {
    if (isset($_SESSION['ui_lang']) && ($_SESSION['ui_lang'] === 'zh' || $_SESSION['ui_lang'] === 'en')) {
        return $_SESSION['ui_lang'];
    }
    $lang = 'zh';
    $ip   = clientIp();
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        [$s, $b] = httpRequest('http://ip-api.com/json/' . $ip . '?fields=countryCode&lang=en', [], null);
        if ($s === 200) {
            $j = json_decode((string)$b, true) ?: [];
            $cc = strtoupper((string)($j['countryCode'] ?? ''));
            if ($cc !== '' && $cc !== 'CN') $lang = 'en';
        }
    }
    $_SESSION['ui_lang'] = $lang;
    return $lang;
}
$LANG = detectLang();

$token = getToken();
$user  = null;
if ($token) {
    [$s, $b] = httpRequest(OAUTH_USERINFO, ['Authorization: Bearer ' . $token]);
    if ($s === 200) { $user = json_decode($b, true) ?: null; }
}
$isGuest = !empty($_SESSION['guest_ok']); // 凭邀请码（游客或登录后输入邀请码）均视为免登录无限生成
$username = $user['preferred_username'] ?? $user['name'] ?? 'Pollinations 用户';
$flashMsg = takeFlash();

// 剩余免费次数（按自然月 / 每账号 5 次统计）
$freeLeft = getToken() ? freeLeft(currentUserId()) : 0;
?>
<!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-CN' ?>">
<head>
<meta charset="UTF-8">
<!-- APP_VERSION:<?= APP_VERSION ?> -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#100C09">
<title><?= $LANG === 'en' ? 'Cursor Maker — AI Windows Cursor Generator' : '光标工坊 · Cursor Atelier — AI 鼠标光标生成器' ?></title>
<style>
  /* ============ 主题 1：暗黑奢华（默认） ============ */
  :root, body.theme-1{
    color-scheme:dark;
    --ink:#F7F1E6;--muted:rgba(247,241,230,.64);--faint:rgba(247,241,230,.40);
    --accent:#E8865F;--accent-deep:#C15F3C;--gold:#E9C07F;
    --line:rgba(247,241,230,.14);--line-strong:rgba(247,241,230,.28);
    --glass:rgba(18,13,10,.76);--field:rgba(255,248,238,.05);
    --ok:#7CC98F;--err:#E38980;
    --veil-1:16,12,9; --veil-w:88; --veil-w2:72; --veil-w3:28; --veil-w4:10;
    --veil-vt:.30; --veil-vb:.72;
    --page-bg:#100C09; --trail:243,192,138;
    --radius:22px;
    --serif:Georgia,"Iowan Old Style","Songti SC","STSong","Noto Serif SC",serif;
    --sans:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  }
  /* ============ 主题 2：卡通清爽 ============ */
  body.theme-2{
    color-scheme:light;
    --ink:#23403A;--muted:rgba(35,64,58,.72);--faint:rgba(35,64,58,.52);
    --accent:#FF8FA3;--accent-deep:#F26B8D;--gold:#F7B267;
    --line:rgba(35,64,58,.14);--line-strong:rgba(35,64,58,.26);
    --glass:rgba(255,251,244,.80);--field:rgba(35,64,58,.06);
    --ok:#5FBF7F;--err:#E66A6A;
    --veil-1:255,250,242; --veil-w:6; --veil-w2:4; --veil-w3:3; --veil-w4:2;
    --veil-vt:.08; --veil-vb:.20;
    --page-bg:#FFF6EA; --trail:247,178,103;
    --radius:26px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{font-family:var(--sans);color:var(--ink);background:var(--page-bg);
    min-height:100vh;min-height:100svh;display:flex;flex-direction:column;align-items:center;
    padding:0 16px 70px;-webkit-tap-highlight-color:transparent;overflow-x:hidden;
    transition:background .6s ease,color .6s ease}

  /* ============ 沉浸式视频背景（双视频交叉淡入） ============ */
  #bgVideo, #bgVideo2{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2;
    filter:saturate(1.12) brightness(1.02) contrast(1.02);
    opacity:0;transition:opacity .8s ease}
  #bgVideo.on, #bgVideo2.on{opacity:1}
  /* 全屏视频背景遮罩：左深右透 —— 左保文字可读，右露视频画面（颜色/深浅随主题切换） */
  .bg-veil{position:fixed;inset:0;z-index:-1;pointer-events:none;
    background:
      linear-gradient(90deg,rgba(var(--veil-1),calc(var(--veil-w)/100)) 0%,rgba(var(--veil-1),calc(var(--veil-w2)/100)) 34%,rgba(var(--veil-1),calc(var(--veil-w3)/100)) 58%,rgba(var(--veil-1),calc(var(--veil-w4)/100)) 100%),
      linear-gradient(180deg,rgba(var(--veil-1),var(--veil-vt)) 0%,transparent 20%,transparent 74%,rgba(var(--veil-1),var(--veil-vb)) 100%);
    transition:opacity .8s ease}

  .wrap{width:100%;max-width:1280px}

  /* ============ 品牌行 ============ */
  .brand{display:flex;align-items:center;justify-content:space-between;width:100%;
    padding:24px 2px 0}
  .brand-mark{display:flex;align-items:center;gap:10px;font-size:11.5px;letter-spacing:.42em;
    color:var(--faint);text-transform:uppercase;font-weight:600}
  .brand-mark .dot{width:7px;height:7px;border-radius:50%;background:var(--accent);
    box-shadow:0 0 14px var(--accent);animation:pulse 3s ease-in-out infinite}
  @keyframes pulse{0%,100%{box-shadow:0 0 8px var(--accent)}50%{box-shadow:0 0 18px var(--accent)}}
  .brand-zh{font-size:11.5px;letter-spacing:.3em;color:var(--faint)}

  /* 免登录入口：右上角悬浮小胶囊 */
  .guest-chip{position:fixed;top:16px;right:16px;z-index:90;
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 14px;border-radius:999px;border:1px solid rgba(58,123,213,.55);
    background:rgba(24,32,48,.55);color:#CFE3FF;font-size:12.5px;font-weight:600;cursor:pointer;
    backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
    box-shadow:0 6px 18px -6px rgba(43,95,168,.5);transition:transform .15s,box-shadow .15s}
  .guest-chip:hover{transform:translateY(-1px);box-shadow:0 8px 22px -6px rgba(43,95,168,.75)}
  .guest-chip:active{transform:translateY(0)}

  /* ============ Hero 首屏：左文字说明 · 右侧留空露出视频背景 ============ */
  .hero{min-height:calc(100vh - 130px);min-height:calc(100svh - 130px);
    display:grid;grid-template-columns:minmax(340px,46%) 1fr;gap:40px;align-items:center;
    padding:36px 4px 44px;position:relative}
  .hero-left{display:flex;flex-direction:column;align-items:flex-start;text-align:left}
  .mascot{width:64px;height:64px;animation:float 4s ease-in-out infinite;
    filter:drop-shadow(0 6px 22px rgba(0,0,0,.55))}
  .mascot .m-body{transform-box:fill-box;transform-origin:center;animation:wobble 4s ease-in-out infinite}
  .mascot .sparkle{animation:twinkle 2.6s ease-in-out infinite}
  .mascot .sparkle.s2{animation-delay:.9s}
  @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
  @keyframes wobble{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(3deg)}}
  @keyframes twinkle{0%,100%{opacity:.3;transform:scale(.8)}50%{opacity:1;transform:scale(1.15)}}

  .kicker{display:flex;align-items:center;gap:14px;margin:22px 0 18px;
    font-size:11.5px;letter-spacing:.5em;text-transform:uppercase;color:var(--gold);
    font-weight:600;text-indent:0}
  .kicker::before{content:"";width:38px;height:1px;flex-shrink:0;
    background:linear-gradient(90deg,transparent,var(--gold))}
  .kicker::after{content:"";width:26px;height:1px;flex-shrink:0;
    background:linear-gradient(90deg,var(--gold),transparent)}

  .hero h1{font-family:var(--serif);font-weight:500;font-size:clamp(40px,6.2vw,68px);
    line-height:1.18;letter-spacing:.04em;text-wrap:balance;
    text-shadow:0 4px 44px rgba(0,0,0,.55);animation:h1In 1s cubic-bezier(.22,1,.36,1) both .12s}
  .hero h1 b{font-weight:600;color:transparent;
    background:linear-gradient(115deg,#F3C08A,#E8865F 52%,#E9C07F 96%);
    -webkit-background-clip:text;background-clip:text}
  @keyframes h1In{from{opacity:0;transform:translateY(16px) scale(.97);filter:blur(6px)}
    to{opacity:1;transform:none;filter:blur(0)}}

  .lede{font-family:var(--serif);color:var(--muted);font-size:clamp(14.5px,1.8vw,16.5px);
    line-height:2.05;letter-spacing:.02em;margin:22px 0 32px;max-width:480px;
    animation:fadeUp .8s cubic-bezier(.22,1,.36,1) both .3s}
  .lede code{background:rgba(233,192,127,.10);border:1px solid rgba(233,192,127,.32);
    color:var(--gold);padding:2px 9px;border-radius:8px;font-size:.84em}
  .hero .btn{width:auto;padding:15px 44px;border-radius:999px;font-size:15.5px;letter-spacing:.16em;
    animation:fadeUp .8s cubic-bezier(.22,1,.36,1) both .45s}

  .scroll-hint{position:absolute;bottom:4px;left:50%;transform:translateX(-50%);
    display:flex;flex-direction:column;align-items:center;gap:9px;
    font-size:10.5px;letter-spacing:.42em;text-transform:uppercase;color:var(--faint);text-indent:.42em}
  .scroll-hint::after{content:"";width:1px;height:36px;
    background:linear-gradient(180deg,var(--gold),transparent);
    animation:scrollPulse 2.2s ease-in-out infinite}
  @keyframes scrollPulse{0%,100%{transform:scaleY(.55);transform-origin:top;opacity:.4}
    50%{transform:scaleY(1);opacity:1}}

  .card{max-width:860px}

  /* ============ 通用卡片（玻璃拟态） ============ */
  .card{background:var(--glass);border:1px solid var(--line);border-radius:var(--radius);
    padding:24px;margin-bottom:18px;backdrop-filter:blur(30px) saturate(1.15);
    -webkit-backdrop-filter:blur(30px) saturate(1.15);
    box-shadow:0 24px 60px -30px rgba(0,0,0,.75);
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease}
  .card:hover{transform:translateY(-3px);border-color:var(--line-strong)}
  .card h2{display:flex;align-items:center;gap:10px;font-size:12px;font-weight:600;color:var(--faint);
    letter-spacing:.3em;margin-bottom:18px;text-transform:uppercase}
  .card h2 .step{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;
    padding:0 6px;border-radius:999px;border:1px solid rgba(233,192,127,.45);color:var(--gold);
    font-size:11.5px;font-weight:700;letter-spacing:0}

  /* 登录后右侧生成工坊面板（浮于视频上，毛玻璃） */
  .maker-panel{background:var(--glass);border:1px solid var(--line);border-radius:var(--radius);
    padding:22px;max-width:400px;margin-left:auto;backdrop-filter:blur(30px) saturate(1.15);
    -webkit-backdrop-filter:blur(30px) saturate(1.15);
    box-shadow:0 24px 60px -30px rgba(0,0,0,.75);
    animation:floatIn .5s ease both}
  .maker-panel h2{display:flex;align-items:center;gap:10px;font-size:12px;font-weight:600;color:var(--faint);
    letter-spacing:.3em;margin-bottom:14px;text-transform:uppercase}
  .maker-panel h2 .step{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;
    padding:0 6px;border-radius:999px;border:1px solid rgba(233,192,127,.45);color:var(--gold);
    font-size:11.5px;font-weight:700;letter-spacing:0}
  @keyframes floatIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}

  /* ============ 表单 ============ */
  .field{margin-bottom:14px}
  .field label{display:block;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:7px}
  .field input[type=text],.field select,.field textarea{
    width:100%;background:var(--field);border:1px solid var(--line);border-radius:13px;
    color:var(--ink);padding:11px 13px;font-size:14px;outline:none;
    transition:border-color .15s,box-shadow .15s,background .15s}
  .field select option{background:#1A140E;color:var(--ink)}
  .field textarea{min-height:108px;resize:vertical;font-family:inherit;line-height:1.7}
  .field input::placeholder,.field textarea::placeholder{color:var(--faint)}
  .field input:focus,.field select:focus,.field textarea:focus{
    border-color:var(--accent);background:rgba(255,248,238,.08);box-shadow:0 0 0 4px rgba(232,134,95,.15)}
  .row{display:flex;gap:12px}.row>div{flex:1;min-width:0}
  .check{display:flex;align-items:center;gap:9px;font-size:14px;cursor:pointer;user-select:none;
    background:var(--field);border:1px solid var(--line);border-radius:12px;padding:10px 12px;color:var(--muted)}
  .free-hype{color:var(--gold);font-weight:700}
  .check input{accent-color:var(--accent);width:16px;height:16px;flex-shrink:0}

  /* ============ 按钮 ============ */
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px 20px;
    border:none;border-radius:14px;cursor:pointer;font-size:16px;font-weight:600;color:#fff;
    background:linear-gradient(180deg,#E8865F,var(--accent-deep));
    box-shadow:0 8px 26px -8px rgba(193,95,60,.7),inset 0 1px 0 rgba(255,255,255,.18);
    touch-action:manipulation;position:relative;overflow:hidden;
    transition:transform .15s,box-shadow .15s,filter .15s}
  .btn:hover:not(:disabled){transform:translateY(-2px);
    box-shadow:0 12px 30px -8px rgba(193,95,60,.8),inset 0 1px 0 rgba(255,255,255,.18)}
  .btn:active:not(:disabled){transform:translateY(0)}
  .btn:disabled{opacity:.55;cursor:not-allowed}
  .btn.ghost{background:rgba(255,248,238,.06);border:1px solid var(--line);color:var(--ink);
    box-shadow:none;font-size:14px}
  .btn.ghost:hover:not(:disabled){border-color:var(--accent);color:var(--accent);
    box-shadow:0 6px 18px -8px rgba(232,134,95,.35)}
  .btn.small{padding:9px 14px;width:auto;font-size:13px}
  .btn.guest{background:linear-gradient(180deg,#3A7BD5,#2B5FA8);margin-top:10px;
    box-shadow:0 8px 26px -8px rgba(43,95,168,.6),inset 0 1px 0 rgba(255,255,255,.2)}
  .btn.guest:hover:not(:disabled){box-shadow:0 12px 30px -8px rgba(43,95,168,.8),inset 0 1px 0 rgba(255,255,255,.2)}
  .invite-field{display:flex;flex-direction:column;gap:10px;margin:14px 0 4px}
  .invite-field input{width:100%;background:var(--field);border:1px solid var(--line);border-radius:13px;
    color:var(--ink);padding:12px 14px;font-size:16px;text-align:center;letter-spacing:.2em;outline:none;
    transition:border-color .15s,box-shadow .15s}
  .invite-field input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(232,134,95,.15)}
  .invite-tip{font-size:12.5px;color:var(--muted);text-align:center;line-height:1.8}
  .btn:not(.ghost)::after{content:"";position:absolute;top:0;left:-80%;width:55%;height:100%;
    background:linear-gradient(105deg,transparent,rgba(255,255,255,.4),transparent);
    transform:skewX(-20deg);animation:sheen 3.6s ease-in-out infinite}
  .btn:disabled::after{animation:none;opacity:0}
  @keyframes sheen{0%,55%{left:-80%}100%{left:145%}}
  :focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}

  /* ============ 状态 ============ */
  .status{display:none;align-items:center;gap:10px;margin-top:16px;font-size:14px;
    padding:11px 14px;border-radius:12px;background:rgba(18,13,10,.85);border:1px solid var(--line);
    backdrop-filter:blur(26px);-webkit-backdrop-filter:blur(26px)}
  .status .spinner{width:16px;height:16px;border-radius:50%;border:2px solid var(--line-strong);
    border-top-color:var(--accent);animation:spin .8s linear infinite;flex-shrink:0}
  @keyframes spin{to{transform:rotate(360deg)}}
  .status.err{color:var(--err);border-color:rgba(227,137,128,.4);background:rgba(68,32,26,.55)}
  .status.ok{color:var(--ok);border-color:rgba(124,201,143,.35);background:rgba(26,48,34,.5)}
  .status a{color:var(--accent)}

  /* ============ 用户栏 / 提示 ============ */
  .userbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
    padding:13px 16px;border:1px solid var(--line);border-radius:16px;background:var(--glass);
    backdrop-filter:blur(30px) saturate(1.15);-webkit-backdrop-filter:blur(30px) saturate(1.15);
    box-shadow:0 18px 44px -26px rgba(0,0,0,.8);margin-bottom:18px;font-size:14px}
  .ub-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0}
  .ub-user{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:14em}
  .chip{display:inline-flex;align-items:center;gap:4px;background:var(--field);
    border:1px solid var(--line);border-radius:999px;padding:4px 10px;font-size:12.5px;color:var(--muted)}
  .chip b{color:var(--gold);font-variant-numeric:tabular-nums}

  .flash{padding:12px 16px;border-radius:14px;background:var(--glass);
    border:1px solid var(--line);backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);
    margin-bottom:18px;font-size:14px;word-break:break-word;color:var(--ink)}
  .flash:empty{display:none}

  /* ============ 免费宣传卡（hero 右列，中英双语） ============ */
  .login-compact{max-width:340px;margin-left:auto;text-align:center;
    background:var(--glass);border:1px solid var(--line);border-radius:20px;
    padding:26px 22px 20px;backdrop-filter:blur(26px) saturate(1.2);-webkit-backdrop-filter:blur(26px) saturate(1.2);
    box-shadow:0 26px 60px -26px rgba(0,0,0,.85);animation:fadeUp .9s cubic-bezier(.22,1,.36,1) both .3s}
  .login-emoji{font-size:30px;line-height:1;margin-bottom:8px;display:block;
    animation:float 4s ease-in-out infinite}
  .free-en{font-size:10.5px;letter-spacing:.34em;text-transform:uppercase;
    color:var(--faint);font-weight:600;margin-bottom:8px}
  .free-badge{font-family:var(--serif);font-size:44px;line-height:1;font-weight:700;
    letter-spacing:.02em;margin-bottom:14px;
    background:linear-gradient(115deg,#F3C08A,#E8865F 55%,#E9C07F 96%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
    filter:drop-shadow(0 4px 18px rgba(232,134,95,.35))}
  .free-badge span{display:block;font-size:15px;font-weight:600;letter-spacing:.5em;
    margin-top:6px;color:var(--gold);-webkit-text-fill-color:var(--gold)}
  .free-line{font-size:14.5px;line-height:1.8;color:var(--ink)}
  .free-line b{color:var(--gold)}
  .free-line.en{font-size:12px;color:var(--muted);font-family:var(--serif)}
  .free-line.en b{color:var(--accent)}
  .free-sub{font-size:11.5px;line-height:1.8;color:var(--faint);margin:12px 0 16px}
  .signin-link{display:inline-block;font-size:12.5px;color:var(--gold);
    text-decoration:none;letter-spacing:.04em;border-bottom:1px dashed rgba(233,192,127,.5);
    padding-bottom:2px;transition:opacity .2s}
  .signin-link:hover{opacity:.75}

  /* ============ 预览与导出 ============ */
  #previewWrap{position:relative;background:
    conic-gradient(#241D15 25%,#191410 0 50%,#241D15 0 75%,#191410 0) 0 0/18px 18px;
    border:1px solid var(--line-strong);border-radius:16px;padding:20px;text-align:center;
    cursor:crosshair;min-height:170px;display:flex;align-items:center;justify-content:center}
  #preview{max-width:230px;max-height:230px;image-rendering:pixelated;display:none;
    box-shadow:0 10px 30px -10px rgba(0,0,0,.8);border-radius:8px}
  #hotspotMark{position:absolute;width:0;height:0;pointer-events:none;display:none}
  #hotspotMark::before,#hotspotMark::after{content:"";position:absolute;background:#FF6B5E;border-radius:2px}
  #hotspotMark::before{left:-7px;top:-1.5px;width:14px;height:3px}
  #hotspotMark::after{left:-1.5px;top:-7px;width:3px;height:14px}

  /* ===== 热点设置模态框 ===== */
  .hotspot-overlay{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;
    background:rgba(var(--veil-1),.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);padding:16px}
  .hotspot-overlay.on{display:flex}
  .hotspot-modal{background:var(--glass);border:1px solid var(--line-strong);border-radius:var(--radius);
    max-width:520px;width:100%;padding:22px;box-shadow:0 24px 60px -20px rgba(0,0,0,.5);animation:popIn .28s ease}
  @keyframes popIn{from{opacity:0;transform:translateY(12px) scale(.96)}to{opacity:1;transform:none}}
  .hotspot-modal h3{margin-bottom:4px}
  .hotspot-modal .hotspot-sub{margin:0 0 14px;font-size:13px;color:var(--muted)}
  .hotspot-stage{position:relative;width:100%;max-height:340px;display:flex;align-items:center;justify-content:center;
    background:repeating-conic-gradient(rgba(var(--veil-1),.35) 0 90deg,rgba(var(--veil-1),.16) 0 180deg) 0 0/2px 2px;
    border:1px dashed var(--line-strong);border-radius:14px;cursor:crosshair;overflow:hidden}
  .hotspot-stage img.hs-img{max-width:100%;max-height:320px;width:auto;height:auto;user-select:none;-webkit-user-drag:none}
  .hotspot-stage .hs-mark{position:absolute;transform:translate(-50%,-50%);width:34px;height:34px;pointer-events:none}
  .hotspot-stage .hs-mark::before,.hotspot-stage .hs-mark::after{content:"";position:absolute;background:#FF6B5E;box-shadow:0 0 0 1px rgba(255,255,255,.85),0 0 8px rgba(255,107,94,.8);border-radius:2px}
  .hotspot-stage .hs-mark::before{left:50%;top:1px;width:3px;height:32px;margin-left:-1.5px}
  .hotspot-stage .hs-mark::after{top:50%;left:1px;height:3px;width:32px;margin-top:-1.5px}
  .hotspot-stage .hs-dot{position:absolute;transform:translate(-50%,-50%);width:8px;height:8px;border-radius:50%;background:#FF6B5E;box-shadow:0 0 0 2px #fff}
  .hotspot-actions{display:flex;gap:10px;margin-top:16px;justify-content:flex-end}
  .hotspot-actions .btn{flex:0 0 auto}
  .hint{font-size:12.5px;color:var(--muted);margin-top:12px;line-height:1.9}
  .hint b{color:var(--gold);font-variant-numeric:tabular-nums}
  .hint code{background:var(--field);border:1px solid var(--line);padding:1px 7px;border-radius:6px;
    font-size:11.5px;color:var(--muted);word-break:break-all}
  .actions{display:flex;gap:10px;margin-top:16px}
  .actions .btn{flex:1;padding:12px;font-size:15px}

  .note{font-size:12px;color:var(--faint);text-align:center;margin-top:28px;line-height:2.1;letter-spacing:.02em}
  .note-link{color:var(--gold);text-decoration:none;border-bottom:1px dashed rgba(233,192,127,.5);
    padding-bottom:1px;transition:opacity .2s}
  .note-link:hover{opacity:.75}

  /* ============ 入场编排 / 鼠标动效 ============ */
  @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
  .wrap>.anim-in{animation:fadeUp .65s cubic-bezier(.22,1,.36,1) both}
  .wrap>.anim-in:nth-of-type(1){animation-delay:.02s}
  .wrap>.anim-in:nth-of-type(2){animation-delay:.1s}
  .wrap>.anim-in:nth-of-type(3){animation-delay:.18s}
  .wrap>.anim-in:nth-of-type(4){animation-delay:.26s}
  .wrap>.anim-in:nth-of-type(5){animation-delay:.34s}
  .wrap>.anim-in:nth-of-type(n+6){animation-delay:.42s}

  .trail-dot{position:fixed;width:8px;height:8px;margin:-4px 0 0 -4px;border-radius:50%;
    pointer-events:none;z-index:9998;
    background:radial-gradient(circle,rgba(var(--trail),.95),rgba(var(--trail),.5) 55%,transparent 72%);
    animation:trailFade .7s ease-out forwards}
  @keyframes trailFade{from{opacity:.9;transform:scale(1)}to{opacity:0;transform:scale(.15)}}
  .ripple{position:fixed;width:12px;height:12px;margin:-6px 0 0 -6px;border:2px solid var(--gold);
    border-radius:50%;pointer-events:none;z-index:9999;animation:rippleOut .55s ease-out forwards}
  @keyframes rippleOut{from{opacity:.85;transform:scale(1)}to{opacity:0;transform:scale(4.2)}}

  @media (max-width:880px){
    .hero{grid-template-columns:1fr;min-height:auto;padding-top:28px}
    .hero-right .maker-panel{max-width:100%;margin-left:0}
    .login-compact{display:none}
  }
  @media (max-width:640px){
    .brand{padding-top:18px}
  }
  @media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;
      transition-duration:.01ms!important}
    html{scroll-behavior:auto}
  }
</style>
</head>
<body class="theme-1">
<video id="bgVideo" class="on" autoplay muted playsinline preload="metadata"
       poster="https://cursor.lanprint.com/poster.jpg" aria-hidden="true" disablepictureinpicture>
  <source src="https://cursor.lanprint.com/showcase_min.mp4" type="video/mp4" data-theme="1">
</video>
<video id="bgVideo2" autoplay muted playsinline preload="metadata"
       aria-hidden="true" disablepictureinpicture>
  <source src="https://cursor.lanprint.com/cartoon_showcase.mp4" type="video/mp4" data-theme="2">
</video>
<div class="bg-veil" aria-hidden="true"></div>

<div class="wrap">
  <!-- 品牌行 -->
  <div class="brand anim-in">
    <div class="brand-mark"><span class="dot"></span>CURSOR&nbsp;MAKER</div>
    <div class="brand-zh"><?= $LANG === 'en' ? 'Cursor Studio' : '光标工坊' ?></div>
  </div>

  <!-- 免登录入口：右上角小胶囊（始终显示，登录后也可输入邀请码） -->
  <button class="guest-chip" type="button" id="inviteChip"><?= $LANG === 'en' ? '🎟️ Guest mode' : '🎟️ 免登录生成' ?></button>

  <!-- 沉浸式首屏：文案浮于全屏视频背景上 -->
  <section class="hero">
    <div class="hero-left">
      <div class="mascot-wrap" aria-hidden="true">
        <svg class="mascot" viewBox="0 0 120 120">
          <g class="m-body">
            <path d="M26 20 L98 58 L61 64 L72 94 Z" fill="#E8865F" stroke="#C15F3C" stroke-width="4" stroke-linejoin="round"/>
            <circle cx="50" cy="53" r="4.6" fill="#241A12"/>
            <circle cx="74" cy="53" r="4.6" fill="#241A12"/>
            <path d="M55 66 Q62 72 70 66" stroke="#241A12" stroke-width="3.4" fill="none" stroke-linecap="round"/>
            <ellipse cx="41" cy="62" rx="8" ry="4.5" fill="#F6C7AC" opacity=".85"/>
            <ellipse cx="83" cy="62" rx="8" ry="4.5" fill="#F6C7AC" opacity=".85"/>
          </g>
          <g class="sparkle"><path d="M16 16 q2.2 4.6 4.6 4.6 q-4.6 2.2 -4.6 4.6 q0 -4.6 -4.6 -4.6 q2.4 0 4.6 -4.6 Z" fill="#E9C07F"/></g>
          <g class="sparkle s2"><path d="M104 22 q2.2 4.6 4.6 4.6 q-4.6 2.2 -4.6 4.6 q0 -4.6 -4.6 -4.6 q2.4 0 4.6 -4.6 Z" fill="#E9C07F"/></g>
        </svg>
      </div>
      <div class="kicker"><?= $LANG === 'en' ? 'Cursor Maker · AI Cursor Studio' : 'Cursor Atelier · 光标工坊' ?></div>
      <h1><?= $LANG === 'en' ? 'One cursor.<br><b>One personality.</b>' : '一枚光标<br><b>一种个性</b>' ?></h1>
      <p class="lede"><?= $LANG === 'en'
        ? 'Every move is a note of your style.<br>Type one line and AI forges your own <code>.cur</code> cursor—<br>transparent · custom hotspot · native Windows.'
        : '每一次移动，都是你风格的注脚。<br>输入一句话，AI 为你铸造专属 <code>.cur</code> 光标——<br>透明背景 · 自定热点 · Windows 原生即用。' ?></p>
      <?php if ($token || $isGuest): ?>
      <a class="btn" href="#maker"><?= $LANG === 'en' ? 'Start crafting' : '开始铸造' ?></a>
      <?php else: ?>
      <a class="btn" href="?action=login"><?= $LANG === 'en' ? 'Start crafting' : '开始铸造' ?></a>
      <?php endif; ?>
    </div>
    <div class="hero-right">
      <!-- 生成工坊：浮于右侧视频上，始终展示制作表单 -->
      <div class="maker-panel">
        <h2><span class="step">1</span> <?= $LANG === 'en' ? 'Describe your cursor' : '描述光标样式' ?></h2>
        <div class="field" style="margin:0">
          <textarea id="prompt" placeholder="<?= $LANG === 'en' ? 'e.g. glowing blue crystal arrow cursor…' : '例如：发光的蓝色水晶箭头鼠标指针…' ?>"></textarea>
        </div>
        <h2 style="margin-top:14px"><span class="step">2</span> <?= $LANG === 'en' ? 'Parameters' : '参数' ?></h2>
        <div class="row" style="margin-bottom:0">
          <div class="field">
            <label for="model"><?= $LANG === 'en' ? 'Model' : '模型' ?></label>
            <select id="model">
              <option value="flux">flux · <?= $LANG === 'en' ? '0.01 USD' : '0.01 元/张' ?></option>
              <option value="zimage" selected>zimage · <?= $LANG === 'en' ? '0.028 USD' : '0.028 元/张' ?></option>
            </select>
          </div>
          <div class="field">
            <label for="curSize"><?= $LANG === 'en' ? 'Cursor size' : '光标尺寸' ?></label>
            <select id="curSize">
              <option value="32">32 × 32</option>
              <option value="48" selected>48 × 48</option>
              <option value="64">64 × 64</option>
              <option value="128">128 × 128</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="styleTpl"><?= $LANG === 'en' ? 'Style preset' : '风格模板' ?></label>
          <select id="styleTpl">
            <option value="默认" selected><?= $LANG === 'en' ? 'Default · cursor icon' : '默认 · 光标图标' ?></option>
            <option value="扁平"><?= $LANG === 'en' ? 'Flat & minimal' : '扁平简洁' ?></option>
            <option value="3D"><?= $LANG === 'en' ? '3D glossy' : '3D 光泽' ?></option>
            <option value="霓虹"><?= $LANG === 'en' ? 'Neon glow' : '霓虹发光' ?></option>
            <option value="卡通"><?= $LANG === 'en' ? 'Cartoon cute' : '卡通可爱' ?></option>
            <option value="金属"><?= $LANG === 'en' ? 'Metallic' : '金属质感' ?></option>
          </select>
        </div>
        <div class="field" style="margin:0">
          <label class="check" title="<?= $LANG === 'en' ? 'Auto-remove connected background, export transparent .cur' : '自动抠除四角连通背景，导出透明 .cur' ?>"><input type="checkbox" id="transparent" checked> <?= $LANG === 'en' ? 'Transparent background' : '背景透明化' ?> <b class="free-hype">· <?= $LANG === 'en' ? FREE_CREDITS . ' free / month' : '每月 ' . FREE_CREDITS . ' 张免费' ?></b></label>
        </div>
        <?php if ($token || $isGuest): ?>
        <button class="btn anim-in" id="genBtn" style="margin-top:16px"><?= $LANG === 'en' ? '✨ Generate cursor' : '✨ 生成光标样式' ?></button>
        <?php else: ?>
        <button class="btn anim-in" id="genBtn" type="button" style="margin-top:16px"
                onclick="window.location='?action=login'"><?= $LANG === 'en' ? '🎟️ Free to start (login)' : '🎟️ 免费生成(登录)' ?></button>
        <?php endif; ?>
        <div class="status anim-in" id="status" role="status" aria-live="polite"></div>
      </div>
    </div><!-- 右列：视频背景 + 生成工坊 -->
    <div class="scroll-hint" aria-hidden="true">Scroll</div>
  </section>

  <div class="flash anim-in"><?= htmlspecialchars((string)$flashMsg) ?></div>

    <!-- 邀请码模态框（免登录 / 登录后均可输入） -->
    <div class="hotspot-overlay" id="inviteOverlay" role="dialog" aria-modal="true" aria-labelledby="inviteTitle">
      <div class="hotspot-modal" style="max-width:360px;text-align:center">
        <h3 id="inviteTitle" style="justify-content:center"><?= $LANG === 'en' ? '🎟️ Unlimited via invite code' : '🎟️ 凭邀请码无限生成' ?></h3>
        <p class="hotspot-sub" style="text-align:center;margin-top:6px"><?= $LANG === 'en' ? 'Enter the invite code to generate unlimited cursors without login.' : '输入邀请码，无需登录即可不限次数生成光标。' ?></p>
        <div class="invite-field">
          <input id="inviteKey" type="text" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="<?= $LANG === 'en' ? 'Invite code' : '邀请码' ?>">
          <div class="invite-tip" id="inviteMsg"></div>
        </div>
        <div class="hotspot-actions" style="justify-content:center">
          <button class="btn ghost" type="button" id="inviteCancel" style="flex:1"><?= $LANG === 'en' ? 'Cancel' : '取消' ?></button>
          <button class="btn" type="button" id="inviteGo" style="flex:1"><?= $LANG === 'en' ? 'Verify & enter' : '验证并进入' ?></button>
        </div>
      </div>
    </div>
    <script>
    (() => {
      const LANG = <?= json_encode($LANG) ?>;
      const overlay = document.getElementById('inviteOverlay');
      const input   = document.getElementById('inviteKey');
      const msg     = document.getElementById('inviteMsg');
      const go      = document.getElementById('inviteGo');
      const cancel  = document.getElementById('inviteCancel');
      const open = () => { overlay.classList.add('on'); msg.textContent = ''; setTimeout(() => input.focus(), 80); };
      // openInvite 供内联 onclick 使用
      window.openInvite = open;
      const close = () => overlay.classList.remove('on');
      const submit = async () => {
        const key = input.value.trim();
        if (!key) { msg.textContent = LANG === 'en' ? 'Please enter the invite code' : '请输入邀请码'; msg.style.color = 'var(--err)'; return; }
        go.disabled = true; msg.textContent = LANG === 'en' ? 'Verifying…' : '验证中…'; msg.style.color = 'var(--muted)';
        try {
          const res = await fetch('?action=invite', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key })
          });
          const d = await res.json();
          if (d.ok) { location.reload(); return; }
          msg.textContent = d.error || (LANG === 'en' ? 'Invalid invite code' : '邀请码无效'); msg.style.color = 'var(--err)';
        } catch (e) {
          msg.textContent = (LANG === 'en' ? 'Request failed: ' : '请求失败：') + e.message; msg.style.color = 'var(--err)';
        } finally { go.disabled = false; }
      };
      go.addEventListener('click', submit);
      cancel.addEventListener('click', close);
      overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
      overlay.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
      input.addEventListener('keydown', e => { if (e.key === 'Enter') submit(); });
      // 右上角免登录小胶囊 → 打开模态框
      const chip = document.getElementById('inviteChip');
      if (chip) chip.addEventListener('click', open);
    })();
    </script>

    <?php if ($token || $isGuest): ?>
    <!-- ============ 已登录 / 已凭邀请码游客：工坊 ============ -->
    <div class="userbar anim-in" id="maker">
      <div class="ub-left">
        <?php if ($isGuest): ?>
          <span class="ub-user"><?= $LANG === 'en' ? '🎟️ Guest · invite code' : '🎟️ 游客 · 邀请码' ?></span>
        <?php else: ?>
          <span class="ub-user">👤 <?= htmlspecialchars($username) ?></span>
        <?php endif; ?>
        <span class="chip"><?php if ($isGuest): ?><?= $LANG === 'en' ? '🎟️ Invite mode' : '🎟️ 邀请码模式' ?><?php else: ?><?= $LANG === 'en' ? '🎁 Free crafts' : '🎁 免费铸造' ?> <b id="freeCount"><?= (int)$freeLeft ?>/<?= FREE_CREDITS ?></b><?php endif; ?></span>
      </div>
      <?php if ($isGuest): ?>
        <a class="btn ghost small" href="?action=login">🔑 <?= $LANG === 'en' ? 'Login' : '登录' ?></a>
      <?php else: ?>
        <a class="btn ghost small" href="?action=logout"><?= $LANG === 'en' ? 'Log out' : '退出登录' ?></a>
      <?php endif; ?>
    </div>

    <div class="card anim-in" id="previewCard" style="display:none;margin-top:18px">
      <h2><?= $LANG === 'en' ? '✨ Preview' : '✨ 预览' ?></h2>
      <div id="previewWrap">
        <img id="preview" alt="cursor preview">
        <div id="hotspotMark"></div>
      </div>
      <div class="hint"><?= $LANG === 'en' ? 'Click the preview to set the hotspot (the pixel where the mouse actually clicks) · Current:' : '点击预览设置热点（热点 = 鼠标实际点击点）· 当前热点：' ?> <b id="hotspotLabel">24, 24</b></div>
      <div class="actions">
        <button class="btn ghost" id="regenBtn">🔄 <?= $LANG === 'en' ? 'Regenerate' : '换一个' ?></button>
        <button class="btn" id="dlBtn">⬇️ <?= $LANG === 'en' ? 'Download .cur' : '下载 .cur 光标' ?></button>
      </div>
    </div>

    <!-- 热点设置模态框：下载前弹出，让用户精确标记鼠标实际点击点 -->
    <div class="hotspot-overlay" id="hsOverlay" role="dialog" aria-modal="true" aria-labelledby="hsTitle">
      <div class="hotspot-modal">
        <h3 id="hsTitle"><?= $LANG === 'en' ? '🎯 Set cursor hotspot (crosshair)' : '🎯 设置光标热点（准星）' ?></h3>
        <p class="hotspot-sub"><?= $LANG === 'en' ? 'The hotspot is where the mouse actually "clicks". Click the <b style="color:var(--gold)">arrow tip / the spot you want to hit</b> on the image, then export.' : '热点就是鼠标真正「点击生效」的地方。请点击图片上的 <b style="color:var(--gold)">箭头尖 / 你想要点中的位置</b>，然后导出。' ?></p>
        <div class="hotspot-stage" id="hsStage">
          <img class="hs-img" id="hsImg" alt="<?= $LANG === 'en' ? 'hotspot preview' : '设置热点预览' ?>">
          <span class="hs-mark" id="hsMark" style="display:none"><span class="hs-dot"></span></span>
        </div>
        <div class="hint" style="margin-top:10px"><?= $LANG === 'en' ? 'Current hotspot:' : '当前热点：' ?> <b id="hsLabel">0, 0</b> (<?= $LANG === 'en' ? 'relative to top-left' : '相对图左上角' ?>)</div>
        <div class="hotspot-actions">
          <button class="btn ghost" id="hsCancel"><?= $LANG === 'en' ? 'Cancel' : '取消' ?></button>
          <button class="btn" id="hsConfirm"><?= $LANG === 'en' ? '✅ Export with this hotspot' : '✅ 以此热点导出' ?></button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="note anim-in">
    Powered by <a class="note-link" href="https://pollinations.ai/apps" target="_blank" rel="noopener">pollinations.ai/apps ↗</a>
  </div>
</div>

<?php if ($token || $isGuest): ?>
<script>
(() => {
  const $ = id => document.getElementById(id);
  const LANG = <?= json_encode($LANG) ?>;
  const prompt   = $('prompt');
  const model    = $('model');
  const curSize  = $('curSize');
  const styleTpl = $('styleTpl');
  const transparent = $('transparent');
  const freeCountEl = $('freeCount');
  const genBtn   = $('genBtn');
  const regenBtn = $('regenBtn');
  const dlBtn    = $('dlBtn');
  const status   = $('status');
  const previewCard = $('previewCard');
  const preview  = $('preview');
  const wrap     = $('previewWrap');
  const mark     = $('hotspotMark');
  const hotspotLabel = $('hotspotLabel');
  const hsOverlay = $('hsOverlay');
  const hsStage   = $('hsStage');
  const hsImg     = $('hsImg');
  const hsMark    = $('hsMark');
  const hsLabel   = $('hsLabel');

  let b64 = null;
  let hx = Math.floor(curSizeNow() / 2), hy = Math.floor(curSizeNow() / 2); // 热点（光标坐标系）

  function setStatus(html, cls) {
    status.className = 'status' + (cls ? ' ' + cls : '');
    status.innerHTML = html;
    status.style.display = 'flex';
  }
  function curSizeNow() { return parseInt(curSize.value, 10) || 48; }
  function clampHotspot() {
    const s = curSizeNow();
    hx = Math.max(0, Math.min(s - 1, hx));
    hy = Math.max(0, Math.min(s - 1, hy));
  }
  function renderHotspot() {
    clampHotspot();
    hotspotLabel.textContent = hx + ', ' + hy;
    const s = curSizeNow();
    mark.style.left = (hx / (s - 1) * 100) + '%';
    mark.style.top  = (hy / (s - 1) * 100) + '%';
    mark.style.display = 'block';
  }

  // 提示词模板：默认提示词
  const CURSOR_SUFFIX = LANG === 'en'
    ? '. Design this as a cursor icon, with a matching diagonal up-right arrow near the top-left edge.'
    : '。请将以上内容设计为一个 cur 图标，在左上角边缘添加一个同色斜向上箭头';
  const STYLE_SUFFIX = LANG === 'en' ? {
    '默认': '',
    '扁平': ', flat minimal design, clean',
    '3D': ', 3D render, highlights, glossy depth',
    '霓虹': ', neon tube glow, neon halo',
    '卡通': ', cartoon cute style, rounded lines, chibi',
    '金属': ', metallic texture, mirror highlights, polished'
  } : {
    '默认': '',
    '扁平': '，扁平化简洁设计，干净利落',
    '3D': '，3D 渲染，高光，立体光泽质感',
    '霓虹': '，霓虹灯管发光效果，霓虹光晕',
    '卡通': '，卡通可爱风格，圆润线条，Q版',
    '金属': '，金属质感，镜面高光，抛光亮面'
  };

  async function generate() {
    const p = prompt.value.trim();
    if (!p) { setStatus('❌ ' + (LANG === 'en' ? 'Please describe your cursor' : '请输入光标样式描述'), 'err'); return; }
    const fullPrompt = p + CURSOR_SUFFIX + (STYLE_SUFFIX[styleTpl.value] || '');
    genBtn.disabled = true;
    setStatus('<span class="spinner"></span> ' + (LANG === 'en' ? 'Generating…' : '正在生成…'));
    try {
      const res = await fetch('?action=generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: fullPrompt, model: model.value, size: 512 })
      });
      const data = await res.json();
      if (!data.ok) {
        const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const msg = esc(data.error || (LANG === 'en' ? 'Generation failed' : '生成失败'));
        if (data.recharge) {
          setStatus('❌ ' + msg + ' <a href="https://enter.pollinations.ai/" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600;text-decoration:underline">' + (LANG === 'en' ? '→ Recharge at official site' : '→ 去官网充值') + '</a>', 'err');
        } else {
          setStatus('❌ ' + msg, 'err');
        }
        return;
      }
      b64 = data.image;
      preview.src = 'data:image/png;base64,' + b64;
      preview.style.display = 'block';
      // 默认热点居中
      const s = curSizeNow();
      hx = Math.floor(s / 2); hy = Math.floor(s / 2);
      clampHotspot();
      renderHotspot();
      previewCard.style.display = 'block';
      if (freeCountEl && typeof data.freeLeft === 'number' && data.pay !== 'guest') {
        freeCountEl.textContent = data.freeLeft + '/<?= FREE_CREDITS ?>';
      }
      const payNote = data.pay === 'free' ? (LANG === 'en' ? ' (free quota, ' + data.freeLeft + ' left)' : '（使用免费额度，剩余 ' + data.freeLeft + ' 次）')
        : data.pay === 'guest' ? (LANG === 'en' ? ' (invite mode)' : '（邀请码模式）') : (LANG === 'en' ? ' (paid with your balance)' : '（使用你的余额支付）');
      setStatus('✅ ' + (LANG === 'en' ? 'Done' : '生成完成') + payNote + (LANG === 'en' ? ', set the hotspot then export' : '，请点击设置热点后导出'), 'ok');
      // 生成完成直接弹出热点设置模态框
      setTimeout(openHotspot, 120);
    } catch (e) {
      setStatus('❌ ' + (LANG === 'en' ? 'Request failed: ' : '请求失败：') + e.message, 'err');
    } finally {
      genBtn.disabled = false;
    }
  }

  // 点击预览设置热点
  wrap.addEventListener('click', e => {
    if (!b64 || preview.style.display === 'none') return;
    const r = preview.getBoundingClientRect();
    const s = curSizeNow();
    const fx = (e.clientX - r.left) / r.width;
    const fy = (e.clientY - r.top) / r.height;
    hx = Math.round(fx * (s - 1));
    hy = Math.round(fy * (s - 1));
    renderHotspot();
  });

  // 打开热点设置模态框（用预览图原尺寸数据源）
  function openHotspot() {
    if (!b64) return;
    hsImg.src = 'data:image/png;base64,' + b64;
    hsOverlay.classList.add('on');
    // 重置标记到当前热点（百分比定位到图中心为默认）
    placeHsMark(hx, hy);
  }
  function closeHotspot() { hsOverlay.classList.remove('on'); hsMark.style.display = 'none'; }

  // 模态框内点击图 → 换算热点并放置标记（相对图，非相对整个 stage）
  hsStage.addEventListener('click', e => {
    const r = hsImg.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    const fx = (e.clientX - r.left) / r.width;
    const fy = (e.clientY - r.top) / r.height;
    const s = curSizeNow();
    hx = Math.max(0, Math.min(s - 1, Math.round(fx * (s - 1))));
    hy = Math.max(0, Math.min(s - 1, Math.round(fy * (s - 1))));
    placeHsMark(hx, hy);
  });

  // 把光标坐标映射到模态框图中显示（模态框图缩放显示，坐标换算回图上像素）
  function placeHsMark(x, y) {
    const s = curSizeNow();
    const imgR = hsImg.getBoundingClientRect();
    const stR  = hsStage.getBoundingClientRect();
    if (imgR.width === 0 || imgR.height === 0) { hsMark.style.display = 'none'; return; }
    const px = x / (s - 1) * imgR.width;
    const py = y / (s - 1) * imgR.height;
    hsMark.style.left = (imgR.left - stR.left + px) + 'px';
    hsMark.style.top  = (imgR.top  - stR.top  + py) + 'px';
    hsMark.style.display = 'block';
    hsLabel.textContent = x + ', ' + y;
  }

  async function downloadCur() {
    if (!b64) return;
    openHotspot();   // 先弹模态框让用户标记热点
  }
  async function doExport() {
    dlBtn.disabled = true;
    setStatus('<span class="spinner"></span> ' + (LANG === 'en' ? 'Generating .cur…' : '正在生成 .cur…'));
    try {
      const res = await fetch('?action=cur', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          image: b64,
          size: curSizeNow(),
          hotspot_x: hx,
          hotspot_y: hy,
          transparent: transparent.checked
        })
      });
      if (!res.ok) { setStatus('❌ ' + (LANG === 'en' ? 'Export failed: HTTP ' : '导出失败：HTTP ') + res.status, 'err'); return; }
      const blob = await res.blob();
      const disp = res.headers.get('Content-Disposition') || '';
      const m = disp.match(/filename="([^"]+)"/);
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = m ? m[1] : 'cursor.cur';
      document.body.appendChild(a); a.click(); a.remove();
      closeHotspot();
      setStatus('✅ ' + (LANG === 'en' ? 'Exported .cur (hotspot ' : '已导出 .cur（热点 ') + hx + ',' + hy + (LANG === 'en' ? ', size ' : '，尺寸 ') + curSizeNow() + (LANG === 'en' ? ')' : '）'), 'ok');
    } catch (e) {
      setStatus('❌ ' + (LANG === 'en' ? 'Export failed: ' : '导出失败：') + e.message, 'err');
    } finally {
      dlBtn.disabled = false;
    }
  }

  genBtn.addEventListener('click', generate);
  regenBtn.addEventListener('click', generate);
  dlBtn.addEventListener('click', downloadCur);
  hsConfirm.addEventListener('click', doExport);
  hsCancel.addEventListener('click', closeHotspot);
  hsOverlay.addEventListener('click', e => { if (e.target === hsOverlay) closeHotspot(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeHotspot(); });
  curSize.addEventListener('change', renderHotspot);
})();
</script>
<?php endif; ?>

<script>
/* 全局动效：背景视频直接触发播放 + 鼠标轨迹 + 点击涟漪（尊重系统"减少动态效果"设置） */
(() => {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 背景视频：静音自动播放；被浏览器策略拦截时在多个时机立即补播
  const v1 = document.getElementById('bgVideo');   // 主题1 暗黑奢华
  const v2 = document.getElementById('bgVideo2');  // 主题2 卡通清爽
  const vids = [v1, v2].filter(Boolean);
  if (vids.length) {
    if (reduced) { vids.forEach(x => { x.pause(); x.removeAttribute('autoplay'); }); }
    else {
      vids.forEach(x => { x.muted = true; x.defaultMuted = true; });
      const kick = () => {
        vids.forEach(x => { if (x.paused) { const p = x.play(); if (p && p.catch) p.catch(() => {}); } });
      };
      // 页面加载各阶段直接触发
      kick();
      vids.forEach(x => ['loadedmetadata', 'loadeddata', 'canplay'].forEach(ev => x.addEventListener(ev, kick)));
      window.addEventListener('pageshow', kick);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) kick(); });
      // 自动播放被拦截时，任意首次交互（含鼠标移动）立即补播
      ['pointerdown', 'pointermove', 'touchstart', 'keydown', 'wheel']
        .forEach(ev => document.addEventListener(ev, kick, { once: true, passive: true }));

      /* ===== 双主题：两视频相互切换，网页风格跟随，无感交叉淡入 ===== */
      const applyTheme = (t) => {
        document.body.classList.toggle('theme-1', t === 1);
        document.body.classList.toggle('theme-2', t === 2);
        document.querySelector('meta[name="theme-color"]')
          .setAttribute('content', t === 2 ? '#FFF6EA' : '#100C09');
      };
      const swapTo = (target) => {
        if (target.classList.contains('on')) return;      // 已在显示
        const cur = target === v1 ? v2 : v1;
        cur.classList.remove('on');
        target.classList.add('on');
        applyTheme(target === v1 ? 1 : 2);
        target.currentTime = 0;                            // 从头开始，保证轮流播放
        const p = target.play(); if (p && p.catch) p.catch(() => {});
      };
      // 各自播完 → 交叉切到另一个（无感）
      if (v1) v1.addEventListener('ended', () => swapTo(v2));
      if (v2) v2.addEventListener('ended', () => swapTo(v1));
    }
  }

  if (reduced) return;

  let last = 0;
  document.addEventListener('pointermove', e => {
    const now = performance.now();
    if (now - last < 45) return;             // 节流
    last = now;
    if (document.querySelectorAll('.trail-dot').length > 12) return;
    const d = document.createElement('span');
    d.className = 'trail-dot';
    d.style.left = e.clientX + 'px';
    d.style.top  = e.clientY + 'px';
    document.body.appendChild(d);
    d.addEventListener('animationend', () => d.remove());
  }, { passive: true });
  document.addEventListener('pointerdown', e => {
    const r = document.createElement('span');
    r.className = 'ripple';
    r.style.left = e.clientX + 'px';
    r.style.top  = e.clientY + 'px';
    document.body.appendChild(r);
    r.addEventListener('animationend', () => r.remove());
  });
})();
</script>
</body>
</html>
