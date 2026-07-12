<?php
/**
 * /v1/upload.php — public consumer API: upload a reward IMAGE and store
 * it on the rewards-foundry domain, returning a same-origin URL the
 * item can reference (logo_url / redeem_image_url).
 *
 *   POST (multipart/form-data)
 *     file     = the image (PNG / JPEG / WebP / GIF, ≤ 4 MB)
 *     sub_id   = SUB-XXX  (consumer's subscription scope)
 *     purpose  = qr | redeem   (which image slot — used only for the
 *                stored path/name; storage is otherwise identical)
 *   Headers: X-Consumer-Key: <api_key>   (preferred)
 *
 * Returns: { ok:true, url:"https://www.rewards-foundry.com/uploads/…", purpose }
 *
 * Storage: public_html/uploads/<consumer_id>/<sub_safe>/<random>.<ext>
 *   — under the webroot so the redeem page (public/redeem.html) and the
 *   QR composer (api/v1/qr.php) can load it same-origin (no logo_proxy).
 *   The deploy mirror EXCLUDES uploads/ (deploy.yml -X uploads/) so
 *   `mirror --delete` never wipes runtime uploads. A hardening
 *   .htaccess is dropped at the uploads root so nothing there executes.
 *
 * Scope: every write is tagged to the auth'd consumer + sub_id; a
 * consumer can only ever write under its own consumer_id path.
 */

declare(strict_types=1);
@set_time_limit(30);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rewards_consumer_auth.php';
require_once __DIR__ . '/../lib/image_store.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

$consumer = rewards_require_consumer();   /* 401s on bad/missing key */

/* ── Inputs ── */
$subId   = trim((string) ($_POST['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
}
$purpose = in_array(($_POST['purpose'] ?? ''), ['qr', 'redeem'], true) ? (string) $_POST['purpose'] : 'qr';

/* ── Validate + store (shared with the admin upload path) ── */
$err = null;
$res = rewards_store_uploaded_image($_FILES['file'] ?? [], $subId, $purpose, (int) $consumer['id'], $err);
if ($res === null) {
    $code = (strpos((string) $err, 'too large') !== false) ? 413
          : ((strpos((string) $err, 'allowed') !== false || strpos((string) $err, 'not a readable') !== false) ? 415 : 400);
    rewards_json_err($err ?: 'upload failed', $code);
}

rewards_json_ok(['url' => $res['url'], 'purpose' => $purpose, 'width' => $res['width'], 'height' => $res['height']]);
