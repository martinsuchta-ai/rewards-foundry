<?php
/**
 * /v1/qr.php — public QR PNG generator for a reward item.
 *
 *   GET ?t=<qr_token>[&size=320][&download=1]
 *   No auth — the token IS the access credential (same threat model
 *   as the redemption page itself).
 *
 * Returns image/png. Theme + logo come from the item row (denormalised
 * at item-create time per carve-out decision 5).
 *
 * Cacheable (24h) since the URL never changes for a token.
 *
 *   ?download=1 — adds Content-Disposition: attachment so the browser
 *                 downloads instead of rendering inline. Filename is
 *                 reward-<token-prefix>.png to keep multi-download
 *                 collisions out of the Downloads folder.
 */

declare(strict_types=1);
@set_time_limit(15);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad token.';
    exit;
}

$size = (int) ($_GET['size'] ?? 320);
if ($size < 120)  $size = 120;
if ($size > 1024) $size = 1024;

try {
    $pdo = rewards_db();
    $st = $pdo->prepare(
        "SELECT i.`qr_token`, i.`is_active`,
                i.`theme_primary_hex`, i.`logo_url`
           FROM `rewards_item` i
          WHERE i.`qr_token` = :t LIMIT 1"
    );
    $st->execute([':t' => $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'qr lookup failed', 500);
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Reward not found.';
    exit;
}

/* Build the public redemption URL the QR encodes. */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$publicUrl = $proto . '://' . $host . '/redeem?t=' . rawurlencode($token);

/* Theme + logo from the item row -- both denormalised at item-create
   time so this endpoint never round-trips back to WBM. White-fallback
   in the helper handles a missing theme_primary_hex; empty logo_url
   means plain QR (no centre logo). */
$themeHex = trim((string) ($row['theme_primary_hex'] ?? '')) ?: '#FFFFFF';
$logoUrl  = trim((string) ($row['logo_url']          ?? ''));

require_once __DIR__ . '/../lib/qr_compose_helper.php';

/* 2026-06-21 — Marty: A/B preview of a "watermark"-style logo
   composition (full-width logo at ~30% opacity over the QR vs
   the current centered logo). Toggled via ?style=watermark.
   Default stays 'centered' so existing surfaces are unchanged. */
$style = isset($_GET['style']) ? (string) $_GET['style'] : 'centered';

$result = wm_qr_compose($publicUrl, $size, $logoUrl, $themeHex, $style);

if (!$result['ok'] || empty($result['png'])) {
    @error_log('[rewards.qr] compose failed for token ' . substr($token, 0, 8)
             . ' (logoUrl=' . $logoUrl . ', error=' . ($result['error'] ?? '?') . ')');
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR generator unreachable. Try again shortly.';
    exit;
}
$png = $result['png'];

if (!empty($result['fallback_reason'])) {
    /* Diagnostic header — visible in DevTools so we can spot
       logo-fallback patterns without spelunking error_log. */
    header('X-Rewards-QR-Fallback: ' . $result['fallback_reason']);
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: public, max-age=86400');
if (!empty($_GET['download'])) {
    $name = 'reward-' . substr($token, 0, 8) . '.png';
    header('Content-Disposition: attachment; filename="' . $name . '"');
}
echo $png;
