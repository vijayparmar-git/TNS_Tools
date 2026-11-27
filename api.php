
<?php
/**
 * Tech and Support - Website Intelligence & Optimization API
 * SEO + AEO + GEO Website Analysis Tool
 *
 * Hosting: cPanel / Shared Hosting
 * PHP: 7.4+
 *
 * GET  api.php?url=https://example.com
 * POST api.php?action=email
 */

declare(strict_types=1);

header('X-TS-Backend: 1');
header('Access-Control-Expose-Headers: X-TS-Backend');

const FROM_EMAIL = 'info@techandsupport.com';
const MAX_BYTES = 2000000;
const MAX_REDIRECTS = 5;
const FETCH_TIMEOUT = 15;
const MAX_PDF_BYTES = 6000000;

// IMPORTANT:
// Set a long random secret before enabling email delivery.
// Your frontend must send the same value in X-TS-Token.
// Do not publish this secret in publicly accessible source code.
const API_TOKEN = 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET';

function json_response(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function public_ipv4(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 |
        FILTER_FLAG_NO_PRIV_RANGE |
        FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Validate URL and resolve a public IPv4 address.
 * The resolved IP is pinned during the cURL request
 * to reduce DNS rebinding / SSRF risks.
 */
function validate_target(string $raw): ?array {

    if (strlen($raw) > 2048) return null;

    $p = parse_url(trim($raw));

    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return null;
    }

    $scheme = strtolower($p['scheme']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    if (isset($p['user']) || isset($p['pass'])) {
        return null;
    }

    $host = strtolower(rtrim($p['host'], '.'));

    if (
        !$host ||
        strpos($host, '.') === false ||
        preg_match('/[\x00-\x20\x7f\/\\\\%]/', $host) ||
        preg_match('/(^|\.)(localhost|local|internal|lan|home|corp)$/i', $host)
    ) {
        return null;
    }

    $port = isset($p['port'])
        ? (int)$p['port']
        : ($scheme === 'https' ? 443 : 80);

    if (!in_array($port, [80, 443], true)) {
        return null;
    }

    // This implementation intentionally supports IPv4 only.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!public_ipv4($host)) return null;
        $ip = $host;
    } else {
        $ips = gethostbynamel($host);

        if (!$ips) return null;

        // Reject the hostname if ANY resolved IPv4 address
        // is private, reserved, or otherwise non-public.
        foreach ($ips as $candidate) {
            if (!public_ipv4($candidate)) return null;
        }

        $ip = $ips[0];
    }

    return [
        'url' => $raw,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'ip' => $ip
    ];
}

/**
 * Resolve absolute, root-relative and path-relative redirects.
 */
function resolve_redirect(string $base, string $location): ?string {

    $location = trim($location);

    if ($location === '' || strlen($location) > 2048) {
        return null;
    }

    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }

    $p = parse_url($base);

    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return null;
    }

    $origin = $p['scheme'] . '://' . $p['host'];

    if (isset($p['port'])) {
        $origin .= ':' . $p['port'];
    }

    if (strpos($location, '//') === 0) {
        return $p['scheme'] . ':' . $location;
    }

    if (strpos($location, '/') === 0) {
        return $origin . $location;
    }

    if (strpos($location, '?') === 0) {
        return $origin . ($p['path'] ?? '/') . $location;
    }

    $path = $p['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);

    $parts = explode('/', $dir . $location);
    $clean = [];

    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;

        if ($part === '..') {
            array_pop($clean);
        } else {
            $clean[] = $part;
        }
    }

    $newPath = '/' . implode('/', $clean);

    return $origin . $newPath;
}

/**
 * Website fetching endpoint.
 */
function fetch_website(string $url): void {

    $current = $url;

    for ($hop = 0; $hop <= MAX_REDIRECTS; $hop++) {

        $target = validate_target($current);

        if (!$target) {
            json_response(400, [
                'error' => 'Invalid or non-public website URL'
            ]);
        }

        $headers = [];
        $body = '';
        $tooLarge = false;

        $ch = curl_init($current);

        $resolve = $target['host'] . ':' .
                   $target['port'] . ':' .
                   $target['ip'];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT =>
                'TechAndSupportAuditBot/2.0 (+https://www.techandsupport.com)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,text/plain;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ],

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$headers) {
                $length = strlen($line);

                if (stripos($line, 'Location:') === 0) {
                    $headers['location'] = trim(substr($line, 9));
                }

                if (stripos($line, 'Content-Type:') === 0) {
                    $headers['content-type'] = trim(substr($line, 13));
                }

                return $length;
            },

            CURLOPT_WRITEFUNCTION => function($ch, $chunk)
                use (&$body, &$tooLarge) {

                $length = strlen($chunk);

                if (strlen($body) + $length > MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return $length;
            }
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlNumber = curl_errno($ch);

        curl_close($ch);

        if ($tooLarge) {
            json_response(413, [
                'error' => 'Website response exceeds the 2 MB limit'
            ]);
        }

        if ($ok === false) {
            json_response(502, [
                'error' => 'Unable to fetch website',
                'details' => $curlNumber === 28
                    ? 'Website connection timed out'
                    : $error
            ]);
        }

        // Handle redirects manually and revalidate every destination.
        if ($status >= 300 && $status < 400) {

            if (empty($headers['location'])) {
                json_response(502, [
                    'error' => 'Website returned an invalid redirect'
                ]);
            }

            if ($hop === MAX_REDIRECTS) {
                json_response(502, [
                    'error' => 'Maximum redirect limit reached'
                ]);
            }

            $next = resolve_redirect(
                $current,
                $headers['location']
            );

            if (!$next) {
                json_response(400, [
                    'error' => 'Invalid redirect destination'
                ]);
            }

            $current = $next;
            continue;
        }

        if ($status < 200 || $status >= 300) {
            json_response(502, [
                'error' => 'Target website returned HTTP ' . $status
            ]);
        }

        $contentType = strtolower($headers['content-type'] ?? '');

        if (
            strpos($contentType, 'text/html') === false &&
            strpos($contentType, 'application/xhtml+xml') === false &&
            strpos($contentType, 'text/plain') === false
        ) {
            json_response(415, [
                'error' => 'Target URL did not return HTML or text content'
            ]);
        }

        header('Content-Type: text/html; charset=utf-8');
        header('X-TS-HTTP-Status: ' . $status);

        echo $body;
        exit;
    }

    json_response(502, [
        'error' => 'Website fetch failed'
    ]);
}

/**
 * PDF email endpoint.
 */
function send_audit_email(): void {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(405, ['error' => 'POST required']);
    }

    if (
        API_TOKEN === 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET' ||
        API_TOKEN === ''
    ) {
        json_response(503, [
            'error' => 'Email API authentication is not configured'
        ]);
    }

    $providedToken = $_SERVER['HTTP_X_TS_TOKEN'] ?? '';

    if (!hash_equals(API_TOKEN, $providedToken)) {
        json_response(401, ['error' => 'Unauthorized']);
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || strlen($raw) > 9000000) {
        json_response(413, ['error' => 'Request too large']);
    }

    $d = json_decode($raw, true);

    if (!is_array($d)) {
        json_response(400, ['error' => 'Invalid JSON request']);
    }

    $to = trim((string)($d['to'] ?? ''));

    if (
        !filter_var($to, FILTER_VALIDATE_EMAIL) ||
        strlen($to) > 254
    ) {
        json_response(400, ['error' => 'Invalid recipient email']);
    }

    $encoded = $d['pdfBase64'] ?? '';

    if (!is_string($encoded) || $encoded === '') {
        json_response(400, ['error' => 'PDF attachment is missing']);
    }

    $pdf = base64_decode($encoded, true);

    if (
        $pdf === false ||
        strlen($pdf) === 0 ||
        strlen($pdf) > MAX_PDF_BYTES ||
        substr($pdf, 0, 5) !== '%PDF-'
    ) {
        json_response(400, ['error' => 'Invalid PDF attachment']);
    }

    $name = htmlspecialchars(
        substr((string)($d['name'] ?? 'there'), 0, 120),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $site = htmlspecialchars(
        substr((string)($d['website'] ?? ''), 0, 500),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $scores = is_array($d['scores'] ?? null)
        ? $d['scores']
        : [];

    $score = function($key) use ($scores) {
        if (!isset($scores[$key]) || !is_numeric($scores[$key])) {
            return 'Not tested';
        }

        return max(0, min(100, (int)$scores[$key])) . '/100';
    };

    $filename = preg_replace(
        '/[^a-zA-Z0-9_.-]/',
        '_',
        basename((string)($d['filename'] ??
            'Tech-and-Support-Website-Audit.pdf'))
    );

    if (!$filename || $filename === '.pdf') {
        $filename = 'Tech-and-Support-Website-Audit.pdf';
    }

    $html = "
    <html>
    <body style='font-family:Arial,sans-serif;color:#26405f'>
      <div style='max-width:600px;margin:auto'>
        <div style='background:#eef6ff;border-bottom:3px solid #1769e0;padding:20px'>
          <h2 style='color:#0b2447;margin:0'>Tech and Support</h2>
          <p>Your Digital Transformation Partner</p>
        </div>

        <div style='padding:20px'>
          <p>Dear {$name},</p>

          <p>
          Thank you for using the Tech and Support Website
          Intelligence &amp; Optimization Tool.
          Your website audit has been completed.
          </p>

          <p><b>Website:</b> {$site}</p>

          <p>
            <b>Overall:</b> {$score('overall')}<br>
            <b>AEO:</b> {$score('aeo')}<br>
            <b>GEO:</b> {$score('geo')}<br>
            <b>AI Crawlers:</b> {$score('crawl')}<br>
            <b>Technical:</b> {$score('tech')}<br>
            <b>Content:</b> {$score('cont')}<br>
            <b>Page Speed:</b> {$score('speed')}
          </p>

          <p>Your detailed audit report is attached as a PDF.</p>

          <p>
          If you need assistance implementing the recommendations,
          our team will be happy to help.
          </p>

          <p>
          Best regards,<br>
          <b>Tech and Support</b><br>
          Website: www.techandsupport.com<br>
          Email: info@techandsupport.com<br>
          Phone / WhatsApp: +91 9772222016
          </p>
        </div>
      </div>
    </body>
    </html>";

    $boundary = 'ts_' . bin2hex(random_bytes(16));

    $subject = 'Your Website SEO, AEO & GEO Audit Report | Tech and Support';

    $headers = [
        'From: Tech and Support <' . FROM_EMAIL . '>',
        'Reply-To: ' . FROM_EMAIL,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"'
    ];

    $body =
        "--{$boundary}\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n\r\n" .
        $html . "\r\n" .

        "--{$boundary}\r\n" .
        "Content-Type: application/pdf; name=\"{$filename}\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n" .
        "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n" .
        chunk_split(base64_encode($pdf)) .
        "--{$boundary}--\r\n";

    $ok = mail(
        $to,
        $subject,
        $body,
        implode("\r\n", $headers)
    );

    if (!$ok) {
        json_response(502, [
            'error' => 'Mail server rejected the message'
        ]);
    }

    json_response(200, [
        'ok' => true,
        'message' => 'Audit report email submitted successfully'
    ]);
}

/**
 * API router.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'email') {
    send_audit_email();
}

if ($method !== 'GET') {
    json_response(405, ['error' => 'Method not allowed']);
}

$url = $_GET['url'] ?? '';

if (!is_string($url) || trim($url) === '') {
    json_response(400, [
        'error' => 'Missing website URL. Use ?url=https://example.com'
    ]);
}

fetch_website(trim($url));
