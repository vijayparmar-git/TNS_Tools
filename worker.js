/**
 * Tech and Support - Audit Tool backend (Cloudflare Worker)
 *   GET  /?url=https://example.com   -> fetches a public page for the audit (raw body, upstream status)
 *   POST /email                      -> emails the PDF report via Resend
 *
 * Variables to set in Cloudflare (Settings > Variables and Secrets):
 *   ALLOWED_ORIGIN   = https://tools.techandsupport.com     (plain text)
 *   FROM_EMAIL       = Tech and Support <info@techandsupport.com>   (plain text; domain must be verified in Resend)
 *   RESEND_API_KEY   = re_xxxxxxxx                          (SECRET)
 */
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 TechAndSupportAuditBot/1.0";
const MAX_BYTES = 2_000_000;

function cors(env, extra = {}) {
  return {
    "Access-Control-Allow-Origin": env.ALLOWED_ORIGIN || "*",
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type",
    "Access-Control-Expose-Headers": "X-TS-Backend, X-Final-URL",
    "X-TS-Backend": "1",
    "Vary": "Origin",
    ...extra,
  };
}
const json = (env, obj, status = 200) =>
  new Response(JSON.stringify(obj), { status, headers: cors(env, { "Content-Type": "application/json" }) });

// SSRF guard: public http(s) hostnames only, no IP literals, no internal names, no odd ports
function safeUrl(raw) {
  let u;
  try { u = new URL(raw); } catch { return null; }
  if (!/^https?:$/.test(u.protocol)) return null;
  const h = u.hostname.toLowerCase();
  if (!h.includes(".") || /^\d+\.\d+\.\d+\.\d+$/.test(h) || h.includes(":") || h.startsWith("[")) return null;
  if (/(^|\.)(localhost|local|internal|lan|home|corp)$/.test(h)) return null;
  if (u.port && !["80", "443"].includes(u.port)) return null;
  return u;
}

async function fetchPage(env, target) {
  let url = target;
  for (let hop = 0; hop < 5; hop++) {
    const res = await fetch(url.href, {
      redirect: "manual",
      headers: { "User-Agent": UA, "Accept": "text/html,application/xhtml+xml,text/plain,*/*;q=0.8", "Accept-Language": "en" },
      cf: { cacheTtl: 0 },
    });
    if (res.status >= 300 && res.status < 400 && res.headers.get("location")) {
      const next = safeUrl(new URL(res.headers.get("location"), url).href); // validate every redirect target
      if (!next) return json(env, { error: "Redirect to a blocked address" }, 400);
      url = next;
      continue;
    }
    const buf = await res.arrayBuffer();
    const body = new TextDecoder().decode(buf.byteLength > MAX_BYTES ? buf.slice(0, MAX_BYTES) : buf);
    return new Response(body, {
      status: res.status,
      headers: cors(env, { "Content-Type": "text/plain; charset=utf-8", "X-Final-URL": url.href }),
    });
  }
  return json(env, { error: "Too many redirects" }, 400);
}

function emailHtml(d) {
  const s = d.scores || {};
  const v = (x) => (x === null || x === undefined ? "Not tested" : (+x || 0) + "/100");
  const name = (d.name || "there").replace(/[<>&"]/g, "");
  const site = String(d.website || "").replace(/[<>&"]/g, "");
  return `<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#26405f">
<div style="background:#eef6ff;border-bottom:3px solid #1769e0;padding:16px 20px"><b style="font-size:20px;color:#0b2447">Tech and Support</b><br><span style="font-size:12px">Your Digital Transformation Partner</span></div>
<div style="padding:20px"><p>Dear ${name},</p>
<p>Thank you for using the Tech and Support Website Intelligence &amp; Optimization Tool. Your website audit has been completed.</p>
<p><b>Website:</b> ${site}</p>
<table cellpadding="8" style="border-collapse:collapse;border:1px solid #d6e6fb"><tr><td>Overall</td><td><b>${v(s.overall)}</b></td><td>AEO</td><td><b>${v(s.aeo)}</b></td><td>GEO</td><td><b>${v(s.geo)}</b></td></tr><tr><td>AI Crawlers</td><td><b>${v(s.crawl)}</b></td><td>Technical</td><td><b>${v(s.tech)}</b></td><td>Content</td><td><b>${v(s.cont)}</b></td></tr><tr><td>Page Speed</td><td><b>${v(s.speed)}</b></td><td colspan="4"></td></tr></table>
<p>Your detailed report is attached as a PDF. It includes technical findings, AI crawler accessibility, content recommendations and a prioritized improvement roadmap.</p>
<p>If you need help implementing the recommendations, our team will be happy to assist.</p>
<p>Best regards,<br>Tech and Support<br>Website: <a href="https://www.techandsupport.com">www.techandsupport.com</a><br>Email: info@techandsupport.com<br>Phone / WhatsApp: +91 9772222016</p></div></div>`;
}

async function sendEmail(env, req) {
  let d;
  try { d = await req.json(); } catch { return json(env, { error: "Invalid request" }, 400); }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.to || "")) return json(env, { error: "Invalid email address" }, 400);
  if (!d.pdfBase64 || d.pdfBase64.length > 9_000_000) return json(env, { error: "Report missing or too large" }, 400);
  if (!env.RESEND_API_KEY || !env.FROM_EMAIL) return json(env, { error: "Email service not configured" }, 503);
  const filename = String(d.filename || "Tech-and-Support-Website-Audit.pdf").replace(/[^\w.\-]/g, "_");
  const r = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: { Authorization: "Bearer " + env.RESEND_API_KEY, "Content-Type": "application/json" },
    body: JSON.stringify({
      from: env.FROM_EMAIL,
      to: [d.to],
      reply_to: "info@techandsupport.com",
      subject: "Your Website SEO, AEO & GEO Audit Report | Tech and Support",
      html: emailHtml(d),
      attachments: [{ filename, content: d.pdfBase64 }],
    }),
  });
  if (!r.ok) return json(env, { error: "Email provider rejected the message" }, 502);
  return json(env, { ok: true });
}

export default {
  async fetch(req, env) {
    if (req.method === "OPTIONS") return new Response(null, { headers: cors(env) });
    const url = new URL(req.url);
    if (req.method === "POST" && url.pathname === "/email") return sendEmail(env, req);
    if (req.method === "GET" && url.searchParams.get("url")) {
      const target = safeUrl(url.searchParams.get("url"));
      if (!target) return json(env, { error: "Only public websites can be audited" }, 400);
      try { return await fetchPage(env, target); } catch { return json(env, { error: "Could not reach the website" }, 502); }
    }
    return json(env, { service: "Tech and Support audit backend", status: "ok" });
  },
};
