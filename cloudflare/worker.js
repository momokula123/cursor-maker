// cursor-cdn: R2 静态托管 Worker（支持视频流式播放）
// - HEAD / GET / OPTIONS
// - Range 分段请求（206 Partial Content，视频拖动与 Safari 播放必需）
// - ETag 协商缓存（304）
const MIME = { ".html":"text/html; charset=utf-8", ".js":"application/javascript; charset=utf-8",
  ".css":"text/css; charset=utf-8", ".json":"application/json; charset=utf-8",
  ".mp4":"video/mp4", ".webm":"video/webm", ".png":"image/png", ".jpg":"image/jpeg",
  ".jpeg":"image/jpeg", ".gif":"image/gif", ".svg":"image/svg+xml",
  ".ico":"image/x-icon", ".cur":"application/octet-stream" };
const ct = p => { const i=p.lastIndexOf("."); return i<0?"application/octet-stream":(MIME[p.slice(i).toLowerCase()]||"application/octet-stream"); };

/** 解析 Range 头（仅支持单区间，覆盖 bytes=0-、bytes=a-b、bytes=-N 三种形式） */
function parseRange(header, size) {
  const m = header.match(/^bytes=(\d*)-(\d*)$/);
  if (!m) return null;
  const [, s, e] = m;
  if (s === "" && e === "") return null;
  if (s === "") {                                  // 后缀区间：最后 N 字节
    const suffix = parseInt(e, 10);
    return { offset: Math.max(0, size - suffix), length: Math.min(suffix, size) };
  }
  const offset = parseInt(s, 10);
  if (offset >= size) return null;
  const length = e === "" ? size - offset : Math.min(parseInt(e, 10) - offset + 1, size - offset);
  return length <= 0 ? null : { offset, length };
}

export default {
  async fetch(req, env) {
    if (req.method !== "GET" && req.method !== "HEAD") {
      return new Response("Method Not Allowed", { status: 405, headers: { Allow: "GET, HEAD" } });
    }
    const u = new URL(req.url);
    let p = decodeURIComponent(u.pathname);
    if (p === "/" || p === "") p = "/index.html";
    if (p.includes("..")) return new Response("Forbidden", { status: 403 });
    const key = p.replace(/^\/+/, "");

    const head = await env.R2.head(key);
    if (!head) return new Response("Not Found", { status: 404 });

    const headers = new Headers();
    headers.set("Content-Type", ct(p));
    headers.set("Accept-Ranges", "bytes");
    headers.set("ETag", head.etag);
    // 媒体长缓存，HTML 短缓存
    headers.set("Cache-Control", p.match(/\.(mp4|webm|png|jpe?g|gif|svg|cur)$/)
      ? "public, max-age=31536000, immutable"
      : "public, max-age=600");

    // 协商缓存
    const inm = req.headers.get("if-none-match");
    if (inm && inm.trim() === head.etag) {
      return new Response(null, { status: 304, headers });
    }

    if (req.method === "HEAD") {
      headers.set("Content-Length", String(head.size));
      return new Response(null, { status: 200, headers });
    }

    // Range 分段（视频流核心）
    const rangeHeader = req.headers.get("range");
    if (rangeHeader) {
      const r = parseRange(rangeHeader, head.size);
      if (!r) {
        headers.set("Content-Range", `bytes */${head.size}`);
        return new Response("Range Not Satisfiable", { status: 416, headers });
      }
      const obj = await env.R2.get(key, { range: r });
      if (!obj) return new Response("Not Found", { status: 404 });
      headers.set("Content-Range", `bytes ${r.offset}-${r.offset + obj.size - 1}/${head.size}`);
      headers.set("Content-Length", String(obj.size));
      return new Response(obj.body, { status: 206, headers });
    }

    const obj = await env.R2.get(key);
    headers.set("Content-Length", String(head.size));
    return new Response(obj.body, { status: 200, headers });
  }
};
