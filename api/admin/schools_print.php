<?php
/**
 * api/admin/schools_print.php — print-friendly HTML for behaviour cards.
 *
 * 2026-06-26. Phase D of the Schools Pilot. The PDF "generator" is
 * actually an HTML page using browser print + CSS @page rules. The
 * admin clicks "Print posters" or "Print cards" in the Schools tab,
 * a new tab opens with this surface, and they hit Ctrl+P → Save as
 * PDF (or print directly).
 *
 * Why HTML and not PHP→PDF? No external PDF library on SiteGround
 * shared hosting, no composer in this repo. HTML print also lets us
 * embed the QR images via the existing /api/v1/qr.php endpoint
 * (which already handles theme + logo composition) without
 * duplicating that pipeline server-side.
 *
 * GET ?catalogue_id=N&layout=<a4_posters|a6_cards>
 *     [&scope=ME|WE|US|GENERIC] [&direction=UP|DOWN] [&dimension=...]
 *     [&include_inactive=1]
 *
 * Auth: admin session (X-Admin-Session OR an admin_session=<token>
 *       query param so a "open in new tab" link works without
 *       re-asserting headers).
 *
 *   layout=a4_posters
 *     One behaviour per page. Large QR (240px), bold title, scope/
 *     direction badges, points. A4 portrait (210 x 297mm).
 *
 *   layout=a6_cards
 *     8 cards per A4 page in a 2x4 grid (each card = A7-ish, 105x74mm).
 *     QR + abbreviated title + scope + points. Cut lines printed
 *     between cells.
 *
 * Theme: uses the catalogue's theme_primary_hex (denormalised from
 * the WBM sub's theme config at catalogue-create time). Each
 * activity ALSO has its own theme_primary_hex (denormalised at
 * activity-create); we use the catalogue's value here for visual
 * consistency across the printed deck.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';

/* Auth — accept session token via X-Admin-Session header (default)
   OR via ?admin_session=<token> for "open in new tab" friendliness. */
if (empty($_SERVER['HTTP_X_ADMIN_SESSION']) && !empty($_GET['admin_session'])) {
    $_SERVER['HTTP_X_ADMIN_SESSION'] = (string) $_GET['admin_session'];
}
rewards_admin_require_session();

$catId    = (int) ($_GET['catalogue_id'] ?? 0);
$layout   = (string) ($_GET['layout'] ?? 'a4_posters');
if ($catId <= 0) {
    http_response_code(400);
    echo 'catalogue_id required';
    exit;
}
if (!in_array($layout, ['a4_posters', 'a6_cards'], true)) {
    $layout = 'a4_posters';
}

$pdo = rewards_db();

/* Resolve catalogue. */
$st = $pdo->prepare(
    "SELECT cat.`id`, cat.`sub_id`, cat.`edition_key`,
            cat.`scope_labels`, cat.`dimension_labels`, cat.`terminology`,
            cat.`trusted_role_label`, cat.`theme_primary_hex`,
            c.`name` AS consumer_name
       FROM `rewards_behaviour_catalogue` cat
       JOIN `rewards_consumer` c ON c.`id` = cat.`consumer_id`
      WHERE cat.`id` = ? LIMIT 1");
$st->execute([$catId]);
$cat = $st->fetch(PDO::FETCH_ASSOC);
if (!$cat) {
    http_response_code(404);
    echo 'catalogue not found';
    exit;
}
$scopeLabels = json_decode((string) $cat['scope_labels'],     true) ?: [];
$dimLabels   = json_decode((string) $cat['dimension_labels'], true) ?: [];
$terms       = json_decode((string) $cat['terminology'],      true) ?: ['spin_up' => 'Spin Up', 'spin_down' => 'Spin Down'];
$themeHex    = (string) ($cat['theme_primary_hex'] ?? '#0F6E56');
$trustedRoleLabel = (string) ($cat['trusted_role_label'] ?? 'Teacher');

/* Filter activities. */
$where  = ['ba.`catalogue_id` = ?'];
$params = [$catId];
if (!empty($_GET['scope']) && in_array($_GET['scope'], ['ME','WE','US','GENERIC'], true)) {
    $where[]  = 'ba.`scope` = ?'; $params[] = $_GET['scope'];
}
if (!empty($_GET['direction']) && in_array($_GET['direction'], ['UP','DOWN'], true)) {
    $where[]  = 'ba.`direction` = ?'; $params[] = $_GET['direction'];
}
if (!empty($_GET['dimension'])) {
    $where[]  = 'ba.`dimension_key` = ?'; $params[] = (string) $_GET['dimension'];
}
if (empty($_GET['include_inactive'])) {
    $where[]  = 'ba.`active` = 1';
}
$whereSql = implode(' AND ', $where);

$st = $pdo->prepare(
    "SELECT ba.`id`, ba.`qr_token`, ba.`scope`, ba.`direction`,
            ba.`dimension_key`, ba.`title`, ba.`points`
       FROM `rewards_behaviour_activity` ba
      WHERE $whereSql
      ORDER BY ba.`scope`, ba.`direction`, ba.`dimension_key`, ba.`id`");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Build the print-ready surface. */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$base  = $proto . '://' . $host;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$qrFor = function ($token) use ($base, $esc) { return $base . '/api/v1/qr.php?t=' . rawurlencode((string) $token); };

$pageTitle = $esc($cat['edition_key']) . ' · ' . $esc($cat['sub_id']) . ' · ' . ($layout === 'a4_posters' ? 'A4 Posters' : 'A6 Cards');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $pageTitle ?> · Print</title>
<meta name="robots" content="noindex,nofollow">
<style>
:root {
  --theme:        <?= $esc($themeHex) ?>;
  --theme-soft:   <?= $esc($themeHex) ?>14;   /* 8% alpha (hex 14 = 20/255 ≈ 8%) */
  --up:           #0F6E56;
  --down:         #BA7517;
  --text:         #161A2E;
  --text-dim:     #4d5366;
  --border:       #d8def0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: var(--text); }
body { padding: 16px; background: #f4f6fb; }

/* ── Print toolbar (hidden when printing) ──────────────────────── */
.toolbar {
  position: sticky; top: 16px; z-index: 100;
  background: #fff; border: 1px solid var(--border); border-radius: 12px;
  padding: 14px 18px;
  display: flex; align-items: center; gap: 14px;
  box-shadow: 0 4px 14px rgba(22,26,46,.06);
  margin-bottom: 18px;
}
.toolbar .toolbar-title { font-weight: 800; font-size: 14px; letter-spacing: -.01em; }
.toolbar .toolbar-meta { font-size: 12px; color: var(--text-dim); }
.toolbar .toolbar-spacer { flex: 1; }
.toolbar button {
  font-family: inherit;
  background: var(--theme); color: #fff;
  border: 0; border-radius: 99px;
  padding: 9px 18px; font-weight: 700; font-size: 13px;
  cursor: pointer;
}
.toolbar button:hover { opacity: .92; }
.toolbar .secondary {
  background: #fff; color: var(--text);
  border: 1px solid var(--border);
}
@media print {
  .toolbar { display: none !important; }
  body { background: #fff; padding: 0; }
}

/* ── A4 poster layout — one card per page ─────────────────────── */
@page {
  size: A4 portrait;
  margin: 8mm;
}

<?php if ($layout === 'a4_posters'): ?>
.poster {
  width: 100%;
  min-height: 277mm;  /* a4 297mm minus 2*10mm margins, less a bit of safety */
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 28px 30px;
  display: flex; flex-direction: column;
  page-break-after: always;
  position: relative; overflow: hidden;
}
.poster:last-child { page-break-after: auto; }
.poster .corner-band {
  position: absolute; top: 0; left: 0; right: 0; height: 18px;
}
.poster .corner-band.up   { background: linear-gradient(90deg, var(--up), #639922); }
.poster .corner-band.down { background: linear-gradient(90deg, var(--down), #7a4a0a); }
.poster .header-row {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-top: 30px; margin-bottom: 28px;
}
.poster .header-row .left { flex: 1; }
.poster .direction-pill {
  display: inline-block;
  font-size: 11px; font-weight: 800; letter-spacing: .14em;
  text-transform: uppercase;
  padding: 6px 14px; border-radius: 99px; color: #fff;
}
.poster .direction-pill.up   { background: var(--up); }
.poster .direction-pill.down { background: var(--down); }
.poster .scope-line {
  font-size: 13px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--text-dim);
  margin-top: 12px;
}
.poster h2 {
  font-size: 30px; font-weight: 800; letter-spacing: -.012em;
  line-height: 1.2; color: var(--text);
  margin-top: 14px;
  max-width: 70%;
}
.poster .points {
  font-size: 36px; font-weight: 800; line-height: 1;
  padding: 16px 24px; border-radius: 18px;
  color: #fff;
  font-variant-numeric: tabular-nums;
}
.poster .points.up   { background: var(--up); }
.poster .points.down { background: var(--down); }
.poster .qr-section {
  flex: 1;
  display: flex; align-items: center; justify-content: center;
  margin: 18px 0;
}
.poster .qr-section img {
  width: 260px; height: 260px;
  border: 8px solid #fff;
  box-shadow: 0 4px 22px rgba(22,26,46,.10);
}
.poster .instr {
  margin-top: auto;
  background: var(--theme-soft);
  border: 1px solid var(--theme);
  border-radius: 12px;
  padding: 16px 20px;
}
.poster .instr h3 { font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--theme); margin-bottom: 8px; }
.poster .instr p  { font-size: 14px; color: var(--text); line-height: 1.6; }
.poster .footer {
  margin-top: 14px;
  display: flex; justify-content: space-between; align-items: center;
  font-size: 11px; color: var(--text-dim);
}
.poster .footer .stamp { font-family: ui-monospace, monospace; opacity: .6; }
<?php else: /* a6_cards layout */ ?>
/* ── 8 cards per A4 page (2 columns x 4 rows) ──────────────────── */
.card-page {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: repeat(4, 70mm);
  gap: 4mm;
  page-break-after: always;
}
.card-page:last-child { page-break-after: auto; }
.cardx {
  border: 1px dashed var(--text-dim);
  border-radius: 6px;
  padding: 8mm;
  display: grid;
  grid-template-columns: 1fr 28mm;
  gap: 4mm;
  background: #fff;
  position: relative;
  overflow: hidden;
}
.cardx::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 4px;
}
.cardx.up::before   { background: linear-gradient(90deg, var(--up), #639922); }
.cardx.down::before { background: linear-gradient(90deg, var(--down), #7a4a0a); }
.cardx .cardx-body { display: flex; flex-direction: column; padding-top: 6px; }
.cardx .cardx-tag {
  font-size: 8px; font-weight: 800; letter-spacing: .12em;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.cardx.up   .cardx-tag { color: var(--up); }
.cardx.down .cardx-tag { color: var(--down); }
.cardx h3 {
  font-size: 11px; font-weight: 800;
  line-height: 1.3; color: var(--text);
  margin-bottom: 6px;
  letter-spacing: -.005em;
  /* Clamp long titles to ~3 lines at A6 scale. */
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
  overflow: hidden;
}
.cardx .cardx-scope {
  font-size: 9px; font-weight: 700;
  color: var(--text-dim);
  margin-top: auto;
}
.cardx .cardx-points {
  display: inline-block;
  font-size: 11px; font-weight: 800;
  padding: 3px 9px; border-radius: 99px; color: #fff;
  margin-top: 4px; align-self: flex-start;
}
.cardx.up   .cardx-points { background: var(--up); }
.cardx.down .cardx-points { background: var(--down); }
.cardx .cardx-qr {
  display: flex; align-items: center; justify-content: center;
}
.cardx .cardx-qr img { width: 26mm; height: 26mm; }
<?php endif; ?>

.empty {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 40px 32px;
  text-align: center;
  color: var(--text-dim);
}
</style>
</head>
<body>

<!-- Toolbar (hidden on print) -->
<div class="toolbar">
  <div>
    <div class="toolbar-title"><?= $pageTitle ?></div>
    <div class="toolbar-meta"><?= count($rows) ?> behaviour<?= count($rows) === 1 ? '' : 's' ?> &middot; <?= $esc($cat['consumer_name']) ?></div>
  </div>
  <div class="toolbar-spacer"></div>
  <button onclick="window.print()">Print &rarr; Save as PDF</button>
  <button class="secondary" onclick="window.close()">Close</button>
</div>

<?php if (count($rows) === 0): ?>
<div class="empty">No behaviours match the current filter. Add some via the importer or reduce filters.</div>
<?php else: ?>

<?php if ($layout === 'a4_posters'): ?>

<?php foreach ($rows as $r):
    $isUp = ((string) $r['direction']) === 'UP';
    $dirLabel = $isUp ? ($terms['spin_up'] ?? 'Spin Up') : ($terms['spin_down'] ?? 'Spin Down');
    $scope = (string) $r['scope'];
    $scopeLabel = (string) ($scopeLabels[$scope] ?? $scope);
    $dim = (string) ($r['dimension_key'] ?? '');
    $dimLabel = $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : '';
    $scopeFullLine = $scopeLabel . ($dimLabel !== '' ? '  ·  ' . $dimLabel : '');
    $sign = $isUp ? '+' : '−';
?>
<div class="poster">
  <div class="corner-band <?= $isUp ? 'up' : 'down' ?>"></div>
  <div class="header-row">
    <div class="left">
      <span class="direction-pill <?= $isUp ? 'up' : 'down' ?>"><?= $esc($dirLabel) ?></span>
      <div class="scope-line"><?= $esc($scopeFullLine) ?></div>
      <h2><?= $esc($r['title']) ?></h2>
    </div>
    <div class="points <?= $isUp ? 'up' : 'down' ?>"><?= $sign ?><?= (int) $r['points'] ?></div>
  </div>
  <div class="qr-section">
    <img src="<?= $esc($qrFor($r['qr_token'])) ?>" alt="QR">
  </div>
  <div class="instr">
    <h3>How to record this</h3>
    <p><?= $isUp ? 'When you see this happen' : 'When this behaviour shows up' ?>, scan the code, enter your email as <?= $esc(strtolower($trustedRoleLabel)) ?>, then the student's email or key. <?= $isUp ? '+' . (int) $r['points'] . ' points land in their wallet immediately.' : (int) $r['points'] . ' points come out of their wallet — a reflection moment, not a punishment.' ?></p>
  </div>
  <div class="footer">
    <span><?= $esc($cat['consumer_name']) ?> &middot; <?= $esc($cat['sub_id']) ?> &middot; <?= $esc($cat['edition_key']) ?></span>
    <span class="stamp"><?= $esc(substr((string) $r['qr_token'], 0, 12)) ?>…</span>
  </div>
</div>
<?php endforeach; ?>

<?php else: /* a6_cards */
    /* Group 8 per page. */
    $perPage = 8;
    for ($i = 0; $i < count($rows); $i += $perPage):
        $batch = array_slice($rows, $i, $perPage);
?>
<div class="card-page">
  <?php foreach ($batch as $r):
    $isUp = ((string) $r['direction']) === 'UP';
    $dirLabel = $isUp ? ($terms['spin_up'] ?? 'Spin Up') : ($terms['spin_down'] ?? 'Spin Down');
    $scope = (string) $r['scope'];
    $scopeLabel = (string) ($scopeLabels[$scope] ?? $scope);
    $dim = (string) ($r['dimension_key'] ?? '');
    $dimLabel = $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : '';
    $sign = $isUp ? '+' : '−';
  ?>
  <div class="cardx <?= $isUp ? 'up' : 'down' ?>">
    <div class="cardx-body">
      <div class="cardx-tag"><?= $esc($dirLabel) ?></div>
      <h3><?= $esc($r['title']) ?></h3>
      <div class="cardx-scope"><?= $esc($scopeLabel) ?><?= $dimLabel !== '' ? ' · ' . $esc($dimLabel) : '' ?></div>
      <span class="cardx-points"><?= $sign ?><?= (int) $r['points'] ?></span>
    </div>
    <div class="cardx-qr">
      <img src="<?= $esc($qrFor($r['qr_token'])) ?>" alt="QR">
    </div>
  </div>
  <?php endforeach; ?>
  <?php
    /* Fill empty cells if the last page is short. */
    for ($k = count($batch); $k < $perPage; $k++):
  ?><div></div><?php endfor; ?>
</div>
<?php endfor; endif; ?>

<?php endif; /* end has-rows */ ?>

</body>
</html>
