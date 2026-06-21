<?php
/**
 * /v1/logo_proxy.php — server-side image fetch + stream.
 *
 * 2026-06-22. Marty: a logo URL hosted on smart-tools-foundry.com
 * works when opened directly in a browser but fails to render as
 * an <img src=...> on rewards-foundry.com (broken-image icon).
 * Classic cross-origin <img> failure mode -- the request sends
 * Referer: rewards-foundry.com, and either SiteGround hot-link
 * protection or some Apache rule on the WBM side returns 403/404
 * when the Referer doesn't match smart-tools-foundry.com.
 *
 * Server-side fetch bypasses it: PHP file_get_contents sends our
 * UA (or no Referer at all by default), the WBM origin serves the
 * bytes normally, we stream them back with the right Content-Type
 * so the browser sees a same-origin image.
 *
 *   GET ?url=<urlencoded full URL>
 *
 * Allowlist: only fetches URLs whose host matches a known
 * trusted origin (smart-tools-foundry.com + its subs +
 * the-good-foundry.com). Open URL proxies are a known SSRF
 * vector -- the allowlist makes this a logo-only relay.
 *
 * Cache: 24h public so the browser + CDN caches per-URL.
 */

declare(strict_types=1);
@set_time_limit(15);

$ALLOWED_HOSTS = [
    'smart-tools-foundry.com',
    'www.smart-tools-foundry.com',
    'the-good-foundry.com',
    'www.the-good-foundry.com',
];

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($url === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'url required';
    exit;
}

/* Parse + validate. Only https. */
$parts = @parse_url($url);
if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'invalid url';
    exit;
}
if (strtolower($parts['scheme']) !== 'https') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'https required';
    exit;
}
$host = strtolower($parts['host']);
if (!in_array($host, $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'host not in allowlist';
    exit;
}

/* Server-side fetch. No Referer header so WBM-side hot-link
   protection doesn't trip; explicit User-Agent so the request
   doesn't read as a bot. 6-second timeout caps a stalled
   upstream. */
$ctx = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'timeout'       => 6,
        'header'        => "User-Agent: RewardsFoundry-LogoProxy/1.0\r\n",
        'ignore_errors' => true,
        'follow_location' => true,
        'max_redirects'   => 3,
    ],
    'https' => [
        'method'        => 'GET',
        'timeout'       => 6,
        'header'        => "User-Agent: RewardsFoundry-LogoProxy/1.0\r\n",
        'ignore_errors' => true,
        'follow_location' => true,
        'max_redirects'   => 3,
    ],
]);
$bytes = @file_get_contents($url, false, $ctx);
if ($bytes === false || strlen($bytes) < 16) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'upstream fetch failed';
    exit;
}

/* Sniff the Content-Type from upstream headers; fall back to
   image/png. $http_response_header is populated by PHP after
   file_get_contents on http:// or https:// streams. */
$contentType = '';
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $contentType = trim(substr($h, 13));
            break;
        }
    }
}
if ($contentType === '' || stripos($contentType, 'image/') !== 0) {
    /* Sniff from magic bytes -- the upstream may have served a
       generic Content-Type for static files. */
    if (substr($bytes, 0, 4) === "\x89PNG")           $contentType = 'image/png';
    elseif (substr($bytes, 0, 3) === "\xff\xd8\xff")  $contentType = 'image/jpeg';
    elseif (substr($bytes, 0, 4) === 'GIF8')          $contentType = 'image/gif';
    elseif (substr($bytes, 0, 4) === 'RIFF')          $contentType = 'image/webp';
    elseif (strpos($bytes, '<svg') !== false)         $contentType = 'image/svg+xml';
    else                                              $contentType = 'image/png';
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
echo $bytes;
