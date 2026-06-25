<?php
/**
 * import_school_behaviours.php — seed rewards_behaviour_activity rows
 *                                from a JSON library.
 *
 * 2026-06-26 — Phase B of the Schools Pilot rollout. Migration 004
 * creates the catalogue rows for SUB-MOVWPL0B (Kids/WPK) +
 * SUB-MOVXLAHI (Students/WPY). This importer reads the behaviour
 * library from docs/schools_behaviour_seed_wpk.json (or _wpy.json),
 * mints a unique qr_token per row, and INSERTs the activities.
 *
 * USAGE — HTTP (recommended; matches existing run.php pattern)
 *
 *   POST /api/migrate/import_school_behaviours.php?token=<REWARDS_MIGRATE_TOKEN>
 *        &sub_id=SUB-MOVWPL0B
 *        &theme_primary_hex=%23e8621a       (optional; URL-encoded #)
 *        &dry_run=1                          (optional; reports without inserting)
 *
 * USAGE — CLI
 *
 *   php import_school_behaviours.php --sub=SUB-MOVWPL0B --theme=#e8621a [--dry-run]
 *
 * BEHAVIOUR
 *
 *   - Resolves the catalogue row for the given sub_id (must exist
 *     from migration 004; errors otherwise).
 *   - Reads docs/schools_behaviour_seed_<edition>.json by the
 *     catalogue's edition_key (lower-cased). Returns 404 if missing.
 *   - For each row in the JSON's behaviours[]:
 *       • Skips section markers (_section, _section_fill_remaining)
 *         and _meta blocks.
 *       • Skips rows whose title is empty or contains 'PLACEHOLDER'
 *         (so partial seeds don't blow up the pilot).
 *       • Mints a unique qr_token (32 hex), checking BOTH
 *         rewards_item.qr_token AND rewards_behaviour_activity.qr_token
 *         so tokens stay globally unique across surfaces.
 *       • Resolves points magnitude: row override → catalogue
 *         default_point_bands[scope] → 5.
 *       • Resolves self_scan_enabled: row override → 1 for UP, 0 for DOWN.
 *       • INSERTs into rewards_behaviour_activity.
 *   - On the catalogue: if theme_primary_hex was passed in (CLI/HTTP)
 *     and the catalogue row doesn't have one set, UPDATE it. Also stamp
 *     the same hex on every newly-created activity row.
 *   - Idempotent re-run safety: rows with the same (consumer_id, sub_id,
 *     scope, direction, dimension_key, title) are NOT re-inserted on
 *     re-run. Uses a content hash check before INSERT.
 *   - Logs to rewards_audit when activities are seeded (action='behaviour_import').
 *
 * Returns JSON:
 *   {
 *     "ok": true,
 *     "sub_id": "SUB-MOVWPL0B",
 *     "edition_key": "WPK",
 *     "catalogue_id": 1,
 *     "seed_file": "docs/schools_behaviour_seed_wpk.json",
 *     "scanned": 122,
 *     "skipped_placeholders": 80,    // section markers + PLACEHOLDER rows
 *     "skipped_duplicates": 0,        // already present in DB
 *     "inserted": 42,
 *     "theme_primary_hex": "#e8621a",
 *     "dry_run": false
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../rewards_cron_auth.php';
require_once __DIR__ . '/../db.php';

/* CLI flag parsing for --sub / --theme / --dry-run. CLI invocation
   skips HTTP token check via rewards_cron_auth_check (trusted by
   filesystem perms — same pattern as the other rewards crons). */
$isCli = (php_sapi_name() === 'cli');
$args  = [];
if ($isCli) {
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z][\w-]*)(?:=(.*))?$/', $a, $m)) {
            $args[$m[1]] = $m[2] ?? '1';
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
}

rewards_cron_auth_check();

try {
    $pdo = rewards_db();
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'db connect failed');
}

/* Resolve inputs (HTTP query / CLI flags). */
$subId = (string) ($_GET['sub_id']    ?? $args['sub']     ?? '');
$theme = (string) ($_GET['theme_primary_hex'] ?? $args['theme']  ?? '');
$dry   = !empty($_GET['dry_run'])     || !empty($args['dry-run']) || !empty($args['dry_run']);

if ($subId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $subId)) {
    _ish_err('sub_id required (e.g. SUB-MOVWPL0B)', 400);
}
if ($theme !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $theme)) {
    _ish_err('theme_primary_hex must look like #RRGGBB', 400);
}

/* Resolve catalogue. Must exist (migration 004 seeded it). */
$cat = null;
try {
    $stmt = $pdo->prepare(
        "SELECT `id`, `consumer_id`, `edition_key`, `default_point_bands`,
                `theme_primary_hex`
           FROM `rewards_behaviour_catalogue`
          WHERE `sub_id` = ?
          LIMIT 1");
    $stmt->execute([$subId]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'catalogue lookup failed');
}
if (!$cat) {
    _ish_err("no rewards_behaviour_catalogue row for sub_id=$subId — apply migration 004 first", 404);
}

$catId       = (int) $cat['id'];
$consumerId  = (int) $cat['consumer_id'];
$editionKey  = (string) $cat['edition_key'];
$pointBands  = json_decode((string) $cat['default_point_bands'], true) ?: [];
$catTheme    = (string) ($cat['theme_primary_hex'] ?? '');

/* Decide which seed file to read. Convention: lowercased edition_key.
   Seeds live in api/seeds/ (NOT docs/) because the rewards-foundry
   deploy workflow only mirrors public/ + api/. docs/ stays local
   for internal planning artifacts. */
$seedFile = __DIR__ . '/../seeds/schools_behaviour_seed_' . strtolower($editionKey) . '.json';
if (!is_file($seedFile)) {
    _ish_err("seed file not found: $seedFile (drop the library JSON in place first)", 404);
}
$rawJson = @file_get_contents($seedFile);
if ($rawJson === false) {
    _ish_err("seed file read failed: $seedFile", 500);
}
$seed = json_decode($rawJson, true);
if (!is_array($seed) || !isset($seed['behaviours']) || !is_array($seed['behaviours'])) {
    _ish_err('seed JSON malformed — expected { behaviours: [ ... ] }', 422);
}

/* Effective theme: input wins, then catalogue row, then NULL. If the
   input differs from the catalogue's stored value, update the
   catalogue too so the bulk-print generator uses the latest. */
$effectiveTheme = $theme !== '' ? $theme : $catTheme;
if ($theme !== '' && $theme !== $catTheme && !$dry) {
    try {
        $upd = $pdo->prepare(
            "UPDATE `rewards_behaviour_catalogue` SET `theme_primary_hex` = ? WHERE `id` = ?");
        $upd->execute([$theme, $catId]);
    } catch (Throwable $_e) { /* non-fatal — log and carry on */
        error_log('[behaviour_import] catalogue theme update failed: ' . $_e->getMessage());
    }
}

/* Walk the behaviours array. Track counts for the response. */
$scanned     = 0;
$skipPlhold  = 0;
$skipDupe    = 0;
$inserted    = 0;
$mintFails   = 0;

foreach ($seed['behaviours'] as $b) {
    if (!is_array($b)) continue;
    $scanned++;

    /* Section markers + meta blocks — skip silently. */
    if (isset($b['_section']) || isset($b['_section_fill_remaining']) || isset($b['_meta'])) {
        $skipPlhold++;
        continue;
    }

    $title = trim((string) ($b['title'] ?? ''));
    if ($title === '' || stripos($title, 'PLACEHOLDER') !== false) {
        $skipPlhold++;
        continue;
    }

    $scope     = strtoupper((string) ($b['scope']     ?? ''));
    $direction = strtoupper((string) ($b['direction'] ?? ''));
    $dim       = isset($b['dimension_key']) ? strtoupper((string) $b['dimension_key']) : null;

    if (!in_array($scope, ['ME','WE','US','GENERIC'], true)) {
        error_log("[behaviour_import] bad scope: " . json_encode($b));
        continue;
    }
    if (!in_array($direction, ['UP','DOWN'], true)) {
        error_log("[behaviour_import] bad direction: " . json_encode($b));
        continue;
    }
    if ($scope !== 'GENERIC' && ($dim === null || $dim === '')) {
        error_log("[behaviour_import] dimension_key required for non-GENERIC scope: " . json_encode($b));
        continue;
    }
    if ($scope === 'GENERIC') $dim = null;

    /* Resolve points: row override → catalogue defaults → fallback 5. */
    $pts = isset($b['points']) ? (int) $b['points'] : null;
    if ($pts === null || $pts <= 0) {
        $pts = (int) ($pointBands[$scope] ?? 5);
    }

    /* Resolve self_scan_enabled: row override → default per direction
       (UP=1, DOWN=0). The catalogue's self_scan_policy still overrides
       this at award time (see Phase C /redeem enhancement). */
    $selfScan = isset($b['self_scan_enabled'])
        ? (int) ((bool) $b['self_scan_enabled'])
        : ($direction === 'UP' ? 1 : 0);

    /* Idempotent dedupe — same (consumer, sub, scope, direction, dim, title)
       indicates the row's already been seeded. Skip silently. */
    try {
        $dq = $pdo->prepare(
            "SELECT `id` FROM `rewards_behaviour_activity`
              WHERE `consumer_id` = ? AND `sub_id` = ?
                AND `scope`       = ? AND `direction` = ?
                AND ((`dimension_key` IS NULL AND ? IS NULL)
                  OR (`dimension_key` = ?))
                AND `title`       = ?
              LIMIT 1");
        $dq->execute([$consumerId, $subId, $scope, $direction, $dim, $dim, $title]);
        if ($dq->fetchColumn()) {
            $skipDupe++;
            continue;
        }
    } catch (Throwable $_e) {
        error_log('[behaviour_import] dedupe check failed: ' . $_e->getMessage());
        continue;
    }

    if ($dry) {
        $inserted++;  /* would-have-been-inserted count in dry-run */
        continue;
    }

    /* Mint a unique qr_token. Same 32-hex pattern as rewards_item.
       Check uniqueness against BOTH tables so a behaviour token
       can't collide with an item token (both resolve via the same
       /redeem?t=). */
    $token = '';
    for ($i = 0; $i < 8; $i++) {
        $cand = bin2hex(random_bytes(16));
        $a = $pdo->prepare("SELECT 1 FROM `rewards_item` WHERE `qr_token` = ? LIMIT 1");
        $a->execute([$cand]);
        if ($a->fetchColumn()) continue;
        $b2 = $pdo->prepare("SELECT 1 FROM `rewards_behaviour_activity` WHERE `qr_token` = ? LIMIT 1");
        $b2->execute([$cand]);
        if ($b2->fetchColumn()) continue;
        $token = $cand;
        break;
    }
    if ($token === '') {
        $mintFails++;
        error_log("[behaviour_import] token mint failed after 8 tries — likely RNG issue");
        continue;
    }

    /* INSERT. */
    try {
        $ins = $pdo->prepare(
            "INSERT INTO `rewards_behaviour_activity`
                (`consumer_id`, `sub_id`, `catalogue_id`,
                 `qr_token`, `scope`, `direction`, `dimension_key`,
                 `title`, `points`, `self_scan_enabled`,
                 `theme_primary_hex`, `active`, `created_by_email`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)");
        $ins->execute([
            $consumerId, $subId, $catId,
            $token, $scope, $direction, $dim,
            $title, $pts, $selfScan,
            ($effectiveTheme !== '' ? $effectiveTheme : null),
            'import_school_behaviours.php',
        ]);
        $inserted++;
    } catch (Throwable $e) {
        error_log('[behaviour_import] INSERT failed for "' . $title . '": ' . $e->getMessage());
    }
}

/* Audit log — one row per import call (not per-row to keep
   rewards_audit readable). */
if (!$dry) {
    try {
        $aud = $pdo->prepare(
            "INSERT INTO `rewards_audit`
                (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
             VALUES (NULL, 'behaviour_import', 'rewards_behaviour_catalogue', ?, ?)");
        $aud->execute([
            (string) $catId,
            json_encode([
                'sub_id'                => $subId,
                'edition_key'           => $editionKey,
                'scanned'               => $scanned,
                'skipped_placeholders'  => $skipPlhold,
                'skipped_duplicates'    => $skipDupe,
                'inserted'              => $inserted,
                'mint_failures'         => $mintFails,
                'theme_primary_hex'     => $effectiveTheme,
                'seed_file'             => basename($seedFile),
            ]),
        ]);
    } catch (Throwable $_e) { /* non-fatal */ }
}

$resp = [
    'ok'                   => true,
    'sub_id'               => $subId,
    'edition_key'          => $editionKey,
    'catalogue_id'         => $catId,
    'seed_file'            => 'docs/' . basename($seedFile),
    'scanned'              => $scanned,
    'skipped_placeholders' => $skipPlhold,
    'skipped_duplicates'   => $skipDupe,
    'inserted'             => $inserted,
    'mint_failures'        => $mintFails,
    'theme_primary_hex'    => $effectiveTheme !== '' ? $effectiveTheme : null,
    'dry_run'              => $dry,
];

if ($isCli) {
    echo json_encode($resp, JSON_PRETTY_PRINT) . "\n";
    exit(0);
} else {
    rewards_json_ok($resp);
}

/* ─────────────────────────────────────────────────────────────── */

function _ish_err(string $msg, int $code = 400): void {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "ERROR ($code): $msg\n");
        exit(1);
    }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
