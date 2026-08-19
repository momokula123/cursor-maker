# 🎯 Cursor Atelier · 光标工坊

> AI 生成专属 Windows 光标（`.cur`）—— 输入一句话，铸造你的个性指针。
> Generate your own Windows cursor (`.cur`) with AI — type a prompt, mint your personality.

**🌐 在线体验 / Live Demo: [http://hk.1r.gs/](http://hk.1r.gs/)**

---

## 📖 项目介绍 / About

**中文**：光标工坊是一个基于 Pollinations 作图 API 的光标生成器。无需设计基础，输入一句话描述（如「发光的蓝色水晶箭头」），AI 立即生成光标样式图片，你可以自由调整尺寸、设置热点（鼠标点击生效点）、一键导出 Windows 原生 `.cur` 光标文件，透明背景开箱即用。

**English**: Cursor Atelier is a cursor maker powered by the Pollinations image API. No design skills needed — type a description (e.g. "glowing blue crystal arrow") and AI instantly creates a cursor design. Adjust size, set the hotspot (where the mouse actually clicks), and export a native Windows `.cur` file with transparent background, ready to use.

---

## ✨ 功能特性 / Features

| 中文 | English |
|---|---|
| AI 生图：flux / zimage 双模型 | AI image gen: flux / zimage models |
| 官方 BYOP OAuth 2.1 + PKCE 登录 | Official BYOP OAuth 2.1 + PKCE login |
| 每月每账号 5 次免费铸造 | 5 free designs per account / month |
| 免登录邀请码，无限生成 | Invite-code access, unlimited generations |
| 导出 Windows 原生 `.cur` 光标 | Export native Windows `.cur` cursor |
| 透明背景自动抠除 | Automatic transparent background |
| 自定义热点（准星） | Custom hotspot (crosshair) |
| 尺寸 32 / 48 / 64 / 128 | Sizes 32 / 48 / 64 / 128 |
| 双主题全屏视频背景沉浸式首页 | Immersive dual-theme fullscreen video hero |
| 多风格模板：扁平 / 3D / 霓虹 / 卡通 / 金属 | Style presets: flat / 3D / neon / cartoon / metal |

---

## 🎬 界面预览 / UI Preview

首页为全屏视频背景沉浸式设计，两套主题（暗黑奢华 / 卡通清爽）自动轮播，左右分栏：左侧品牌文案，右侧生成工坊。

*The hero features a fullscreen auto-playing video with two themes (dark luxury / pastel cartoon) rotating automatically — brand copy on the left, the maker panel on the right.*

---

## 🚀 本地运行 / Run Locally

需要 PHP 8+（curl / gd / session / openssl 扩展），Windows 直接双击 `start.bat`，或：

```bash
php -S 0.0.0.0:5551 -t .
# 访问 http://127.0.0.1:5551/
```

> **部署配置**：复制 `config.example.php` 为 `config.php`，填入你的 Pollinations App Key 与开发者密钥。`config.php` 已在 `.gitignore` 中排除，绝不提交。

---

## 🧩 技术栈 / Tech Stack

- **后端**: PHP 8+（cURL / GD）
- **生图**: [Pollinations AI](https://pollinations.ai/apps)（flux / zimage）
- **认证**: 官方 BYOP OAuth 2.1 + PKCE
- **导出**: ICONDIR + PNG 封装 `.cur`（Windows Vista+ 原生支持）
- **部署**: PHP 内置服务器 / 宝塔 / Cloudflare（R2 + Worker 托管素材）

---

## 📜 许可 / License

仅个人 / 学习使用。生图费用由使用者的 Pollinations 账号余额承担，请遵守 [Pollinations](https://pollinations.ai) 服务条款。

*For personal / educational use. Generation costs are covered by the user's own Pollinations balance — please comply with the [Pollinations](https://pollinations.ai) terms of service.*
