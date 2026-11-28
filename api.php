<?php
/**
 * Tech and Support Audit Tool - PHP backend for cPanel / shared hosting (no Cloudflare needed).
 * Upload next to index.html on tools.techandsupport.com.
 *   GET  api.php?url=https://example.com  -> fetches a public page (needs PHP cURL)
 *   POST api.php?action=email             -> emails the PDF using the server's mail()
 */
header('X-TS-Backend: 1');
header('Access-Control-Expose-Headers: X-TS-Backend');
const FROM_EMAIL = 'info@techandsupport.com';   // must be a mailbox on your own domain for good deliverability
const MAX_BYTES  = 2000000;

if (isset($_GET['ping'])) {
  header('Content-Type: application/json');
  echo json_encode(['backend' => 'ok', 'php' => PHP_VERSION, 'curl' => function_exists('curl_init'), 'mail' => function_exists('mail')]);
  exit;
}
function is_public_host($host) {
  if (!strpos($host, '.') || preg_match('/(^|\.)(localhost|local|internal|lan|home|corp)$/i', $host)) return false;
  $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
  if (!$ips) return false;
  foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return true;
}
function safe_url($raw) {
  $p = parse_url($raw);
  if (!$p || !isset($p['scheme'], $p['host']) || !in_array($p['scheme'], ['http', 'https'])) return null;
  if (isset($p['port']) && !in_array($p['port'], [80, 443])) return null;
  return is_public_host($p['host']) ? $raw : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'email') {
  header('Content-Type: application/json');
  $d = json_decode(file_get_contents('php://input'), true);
  $to = $d['to'] ?? '';
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || empty($d['pdfBase64']) || strlen($d['pdfBase64']) > 9000000) {
    http_response_code(400); echo json_encode(['error' => 'Invalid request']); exit;
  }
  $name = htmlspecialchars($d['name'] ?? 'there', ENT_QUOTES);
  $site = htmlspecialchars($d['website'] ?? '', ENT_QUOTES);
  $s = $d['scores'] ?? [];
  $v = function ($k) use ($s) { return isset($s[$k]) && $s[$k] !== null ? (int)$s[$k] . '/100' : 'Not tested'; };
  $file = preg_replace('/[^\w.\-]/', '_', $d['filename'] ?? 'Tech-and-Support-Website-Audit.pdf');
  $html = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#26405f'>"
    . "<div style='background:#eef6ff;border-bottom:3px solid #1769e0;padding:16px 20px'><b style='font-size:20px;color:#0b2447'>Tech and Support</b><br><span style='font-size:12px'>Your Digital Transformation Partner</span></div>"
    . "<div style='padding:20px'><p>Dear $name,</p><p>Thank you for using the Tech and Support Website Intelligence &amp; Optimization Tool. Your website audit has been completed.</p>"
    . "<p><b>Website:</b> $site</p><p>Overall: <b>{$v('overall')}</b> | AEO: <b>{$v('aeo')}</b> | GEO: <b>{$v('geo')}</b> | AI Crawlers: <b>{$v('crawl')}</b> | Technical: <b>{$v('tech')}</b> | Content: <b>{$v('cont')}</b> | Page Speed: <b>{$v('speed')}</b></p>"
    . "<p>Your detailed report is attached as a PDF.</p><p>If you need help implementing the recommendations, our team will be happy to assist.</p>"
    . "<p>Best regards,<br>Tech and Support<br>Website: www.techandsupport.com<br>Email: info@techandsupport.com<br>Phone / WhatsApp: +91 9772222016</p></div></div>";
  $b = 'ts_' . md5(uniqid('', true));
  $headers = "From: Tech and Support <" . FROM_EMAIL . ">\r\nReply-To: " . FROM_EMAIL . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
  $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n"
    . "--$b\r\nContent-Type: application/pdf; name=\"$file\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$file\"\r\n\r\n"
    . chunk_split($d['pdfBase64']) . "--$b--";
  $ok = mail($to, 'Your Website SEO, AEO & GEO Audit Report | Tech and Support', $body, $headers);
  if (!$ok) { http_response_code(502); echo json_encode(['error' => 'Mail server rejected the message']); exit; }
  echo json_encode(['ok' => true]); exit;
}

$url = safe_url($_GET['url'] ?? '');
if (!$url) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'Only public websites can be audited']); exit; }
if (!function_exists('curl_init')) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['error' => 'PHP cURL extension is not enabled on this server']); exit; }
$status = 0; $body = '';
for ($hop = 0; $hop < 5; $hop++) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 TechAndSupportAuditBot/1.0',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain,*/*;q=0.8'],
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) { http_response_code(502); header('Content-Type: application/json'); echo json_encode(['error' => 'Could not reach the website']); exit; }
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $head = substr($raw, 0, $hs); $body = substr($raw, $hs);
  curl_close($ch);
  if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(\S+)/mi', $head, $m)) {
    $next = safe_url(rtrim(preg_match('#^https?://#i', $m[1]) ? $m[1] : (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/' . ltrim($m[1], '/'))));
    if (!$next) { http_response_code(400); echo 'Blocked redirect'; exit; }
    $url = $next; continue;
  }
  break;
}
http_response_code($status ?: 502);
header('Content-Type: text/plain; charset=utf-8');
echo substr($body, 0, MAX_BYTES);
