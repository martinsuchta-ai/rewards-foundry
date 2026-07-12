<?php
/**
 * /admin/upload.php — admin-session image upload (central admin UI).
 *
 * Mirror of /v1/upload.php but gated on an admin session instead of a
 * consumer key, so the rewards-foundry admin can attach reward images
 * (QR-page logo + redemption-page image) when creating/editing items
 * from public/admin. Stores via the shared image_store lib under
 * public_html/uploads/admin/<subSafe>/… and returns the same-origin URL.
 *
 *   POST (multipart/form-data)
 *     file    = image (PNG/JPEG/WebP/GIF, ≤ 4 MB)
 *     sub_id  = SUB-XXX (or the item's sub scope)
 *     purpose = qr | redeem
 *   Header: X-Admin-Session: <session>
 *
 * Returns: { ok:true, url, purpose, width, height }
 */

declare(strict_types=1);
@set_time_limit(30);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';
require_once __DIR__ . '/../lib/image_store.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Session');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

rewards_admin_require_session();   /* 401s if no valid session */

$subId   = trim((string) ($_POST['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
}
$purpose = in_array(($_POST['purpose'] ?? ''), ['qr', 'redeem'], true) ? (string) $_POST['purpose'] : 'qr';

$err = null;
$res = rewards_store_uploaded_image($_FILES['file'] ?? [], $subId, $purpose, 'admin', $err);
if ($res === null) {
    $code = (strpos((string) $err, 'too large') !== false) ? 413
          : ((strpos((string) $err, 'allowed') !== false || strpos((string) $err, 'not a readable') !== false) ? 415 : 400);
    rewards_json_err($err ?: 'upload failed', $code);
}

rewards_json_ok(['url' => $res['url'], 'purpose' => $purpose, 'width' => $res['width'], 'height' => $res['height']]);
