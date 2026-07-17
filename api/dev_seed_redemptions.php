<?php
/**
 * dev_seed_redemptions.php — seed random TEST claims for a sub.
 *
 * 2026-07-17 (Marty: "seed say 50 redemption records - random for the WAWL sub
 * - SUB-MQ4KQLLF for testing purposes"). Exercises the export's period / HSA /
 * void filters with realistic spread before real scanning starts.
 *
 * NOT a migration on purpose: test data must be removable. A migration would
 * apply once, be recorded as done, and leave the rows behind forever.
 *
 * SAFETY — this writes rows that convert to REAL money downstream:
 *   - Token-gated (REWARDS_MIGRATE_TOKEN), same as the migration runner.
 *   - Every seeded person uses an @rewards-test.invalid address. `.invalid` is
 *     RFC-2606 reserved and can never be a real mailbox, so a seeded row can
 *     never be mistaken for — or pay out to — a real person.
 *   - Cleanup is one call: ?action=purge removes exactly those rows and
 *     nothing else.
 *
 * GET ?action=seed&token=<T>&sub_id=SUB-XXX[&n=50][&days=90][&void_pct=10]
 * GET ?action=purge&token=<T>&sub_id=SUB-XXX
 * GET ?action=count&token=<T>&sub_id=SUB-XXX
 */

declare(strict_types=1);
require_once __DIR__ . '/rewards_bootstrap.php';
require_once __DIR__ . '/rewards_cron_auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
/* Uncacheable — SiteGround's proxy caches GET responses and would replay a
   stale "seeded 50" while doing nothing. See migrate/run.php. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

rewards_cron_auth_check();   /* 403 unless ?token=REWARDS_MIGRATE_TOKEN */

const TEST_DOMAIN = '@rewards-test.invalid';

$pdo    = rewards_db();
$action = strtolower(trim((string) ($_GET['action'] ?? 'seed')));
$subId  = trim((string) ($_GET['sub_id'] ?? ''));
if ($subId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'sub_id required']);
    exit;
}

$cid = (int) ($pdo->query("SELECT `id` FROM `rewards_consumer` WHERE `name` = 'WBM-prod' LIMIT 1")->fetchColumn() ?: 0);
if ($cid <= 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'WBM-prod consumer not found']);
    exit;
}

/* ── count / purge ─────────────────────────────────────────────────────── */
if ($action === 'count' || $action === 'purge') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `rewards_redemption`
                          WHERE `consumer_id` = ? AND `sub_id` = ?
                            AND `redeemer_email` LIKE ?");
    $st->execute([$cid, $subId, '%' . TEST_DOMAIN]);
    $n = (int) $st->fetchColumn();

    if ($action === 'count') {
        echo json_encode(['ok' => true, 'sub_id' => $subId, 'test_claims' => $n]);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM `rewards_redemption`
                           WHERE `consumer_id` = ? AND `sub_id` = ?
                             AND `redeemer_email` LIKE ?");
    $del->execute([$cid, $subId, '%' . TEST_DOMAIN]);
    echo json_encode(['ok' => true, 'sub_id' => $subId, 'purged' => $n]);
    exit;
}

/* ── seed ──────────────────────────────────────────────────────────────── */
$n       = max(1, min(500, (int) ($_GET['n'] ?? 50)));
$days    = max(1, min(730, (int) ($_GET['days'] ?? 90)));
$voidPct = max(0, min(100, (int) ($_GET['void_pct'] ?? 10)));

$items = $pdo->prepare("SELECT `id`, `name`, `points_allocated`, `money_value_per_point`, `currency`
                          FROM `rewards_item`
                         WHERE `consumer_id` = ? AND `sub_id` = ?
                           AND `is_active` = 1 AND (`archived` IS NULL OR `archived` = 0)");
$items->execute([$cid, $subId]);
$rows = $items->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'no active rewards for ' . $subId . ' — run the seed migration first']);
    exit;
}

/* A small cast of testers, so the export aggregates several claims per person
   rather than 50 people with one claim each — that is what a real quarter
   looks like, and it exercises the per-person rollup. */
$people = [];
foreach ([['Ava','Nguyen'],['Ben','Carter'],['Chloe','Diaz'],['Daniel','Osei'],['Elena','Rossi'],
          ['Frank','Muller'],['Grace','Okafor'],['Hana','Sato'],['Isaac','Levy'],['Jia','Chen'],
          ['Kofi','Mensah'],['Lena','Novak']] as $p) {
    $people[] = [
        'email' => strtolower($p[0] . '.' . $p[1]) . TEST_DOMAIN,
        'first' => $p[0], 'last' => $p[1],
    ];
}

$hasSrc = ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption'
                                AND COLUMN_NAME = 'award_source'")->fetchColumn()) > 0;
$hasVoid = ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption'
                                 AND COLUMN_NAME = 'voided'")->fetchColumn()) > 0;

$cols = ['`consumer_id`','`rewards_item_id`','`sub_id`','`redeemer_email`',
         '`points_awarded`','`money_value`','`currency`','`redeemed_at`'];
$ph   = ['?','?','?','?','?','?','?','?'];
if ($hasSrc)  { $cols[] = '`award_source`';     $ph[] = '?'; }
if ($hasSrc)  { $cols[] = '`awarded_by_email`'; $ph[] = '?'; }
if ($hasVoid) { $cols[] = '`voided`';           $ph[] = '?'; }
if ($hasVoid) { $cols[] = '`void_reason`';      $ph[] = '?'; }

$ins = $pdo->prepare('INSERT INTO `rewards_redemption` (' . implode(',', $cols) . ')
                      VALUES (' . implode(',', $ph) . ')');

$made = 0; $voided = 0; $manual = 0; $pointsTotal = 0; $moneyTotal = 0.0;
for ($i = 0; $i < $n; $i++) {
    $it  = $rows[random_int(0, count($rows) - 1)];
    $per = $people[random_int(0, count($people) - 1)];

    $pts   = (int) $it['points_allocated'];
    $money = round($pts * (float) $it['money_value_per_point'], 4);

    /* Spread across the window, at plausible hours (06:00–20:59) — a gym scan
       at 03:00 would look wrong in the calendar view. */
    $when = gmdate('Y-m-d H:i:s', strtotime(
        '-' . random_int(0, $days - 1) . ' days ' .
        (string) random_int(6, 20) . ':' . sprintf('%02d', random_int(0, 59)) . ':00'
    ));

    $isManual = ($hasSrc && random_int(1, 100) <= 15);   /* ~15% manual awards */
    $isVoid   = ($hasVoid && random_int(1, 100) <= $voidPct);

    $vals = [$cid, (int) $it['id'], $subId, $per['email'], $pts, $money, (string) $it['currency'], $when];
    if ($hasSrc)  { $vals[] = $isManual ? 'MANUAL' : 'QR'; }
    if ($hasSrc)  { $vals[] = $isManual ? ('seed-admin' . TEST_DOMAIN) : null; }
    if ($hasVoid) { $vals[] = $isVoid ? 1 : 0; }
    if ($hasVoid) { $vals[] = $isVoid ? 'Seeded test void' : null; }

    $ins->execute($vals);
    $made++;
    if ($isManual) $manual++;
    if ($isVoid)   { $voided++; }
    else           { $pointsTotal += $pts; $moneyTotal += $money; }
}

echo json_encode([
    'ok'              => true,
    'sub_id'          => $subId,
    'seeded'          => $made,
    'manual_awards'   => $manual,
    'voided'          => $voided,
    'people'          => count($people),
    'window_days'     => $days,
    'items_used'      => count($rows),
    /* Non-voided only — this is what the export should total. */
    'points_active'   => $pointsTotal,
    'money_active'    => round($moneyTotal, 2),
    'note'            => 'Test rows use ' . TEST_DOMAIN . ' (RFC-2606 reserved — can never be a real mailbox). Remove with ?action=purge.',
], JSON_UNESCAPED_SLASHES);
