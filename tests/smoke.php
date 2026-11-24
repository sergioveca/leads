<?php
declare(strict_types=1);

/*
 * End-to-end smoke test against a scratch database (CI: .github/workflows/ci.yml).
 * Applies migrations, imports the shipped Treasurer CSVs, scores, and exercises the lead pipeline.
 * Never point this at the production database: it changes lead status and deliveries.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Leads\Db;
use Leads\LeadQuery;
use Leads\Pipeline;
use Leads\Scoring;
use Leads\Sources\Treasurer;

if (!str_contains((string)\Leads\Env::get('DB_DATABASE'), 'test')) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must contain 'test'\n");
    exit(2);
}

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    $fails += $ok ? 0 : 1;
}

$migs = array_column(Db::all('SELECT name FROM migrations ORDER BY name'), 'name');
check(in_array('003_lead_status_delivery.sql', $migs, true), 'migrations applied: ' . implode(', ', $migs));
check((int)Db::val('SELECT COUNT(*) FROM users') === 1, 'admin user seeded');

$t = microtime(true);
$n = 0;
do {
    $got = Treasurer::import(20000);
    $n += $got;
} while ($got > 0);
$csvRows = 0;
foreach (glob(LEADS_ROOT . '/data/treasurer/wayne_forfeited_*.csv') as $f) {
    $csvRows += count(file($f)) - 1;
}
check($n === $csvRows, sprintf('treasurer import %d rows of %d (%.1fs)', $n, $csvRows, microtime(true) - $t));
check(Treasurer::import() === 0, 'second import is a no-op');

while (Scoring::rescore(5000) > 0) {
}
check((int)Db::val('SELECT COUNT(*) FROM properties WHERE needs_score = 1') === 0, 'all properties scored');
check((int)Db::val("SELECT COUNT(*) FROM properties WHERE status = 'new'") === (int)Db::val('SELECT COUNT(*) FROM properties'), 'every lead starts as New');

$admin = Db::one('SELECT * FROM users LIMIT 1');
$hd = (int)Db::val("SELECT id FROM clients WHERE name = 'Home Detroit'");
[$a, $b] = array_column(Db::all('SELECT parcel_id FROM properties ORDER BY score DESC, parcel_id LIMIT 2'), 'parcel_id');

// status + note
check(Pipeline::update($a, 'contacted', 'Spoke to owner, wants 40k', $admin) === 'Status Contacted.', 'status change with note');
check(Pipeline::update($a, 'contacted', 'Follow up Friday', $admin) === 'Note added.', 'note only');
check(Pipeline::update($a, 'contacted', '  ', $admin) === 'Nothing to save.', 'empty save is a no-op');
$ev = Pipeline::events($a);
check(count($ev) === 2 && $ev[1]['kind'] === 'status' && $ev[1]['body'] === 'Spoke to owner, wants 40k' && $ev[0]['kind'] === 'note', 'timeline has status + note');
try {
    Pipeline::update($a, 'bogus', '', $admin);
    check(false, 'unknown status rejected');
} catch (\InvalidArgumentException) {
    check(true, 'unknown status rejected');
}

// pool / delivered views
$count = static function (array $in, ?array $user) {
    [$w, $p] = LeadQuery::where(LeadQuery::filters($in), $user);
    return (int)Db::val("SELECT COUNT(*) FROM properties p WHERE $w", $p);
};
$poolBefore = $count(['min' => 0], $admin);
[$ok, $skip] = Pipeline::deliver([$a, $b], $hd, 'podio', 'batch 1', $admin);
check($ok === 2 && $skip === 0, 'deliver 2 leads to Home Detroit');
check($count(['min' => 0], $admin) === $poolBefore - 2, 'delivered leads leave the working pool');
check($count(['view' => 'delivered', 'status' => 'any'], $admin) === 2, 'delivered view lists them (no score floor)');
check($count(['view' => 'delivered', 'status' => 'any', 'client' => $hd], $admin) === 2, 'delivered view filters by client');
check(Pipeline::workable([$a, $b], $admin) === [], '4Return admin cannot trace/push Home Detroit leads');
check(Pipeline::canEdit(Db::one('SELECT * FROM properties WHERE parcel_id = ?', [$a]), $admin), 'admin can still record outcomes');
$other = (int)Db::val("SELECT id FROM clients WHERE name = '4Return'");
[$ok, $skip] = Pipeline::deliver([$a], $other, 'email', '', $admin);
check($ok === 0 && $skip === 1, 'cannot deliver a lead already exclusive to another client');

// client users
$hdUser = ['id' => null, 'role' => 'client', 'client_id' => $hd];
$fourUser = ['id' => null, 'role' => 'client', 'client_id' => $other];
check(Pipeline::workable([$a], $hdUser) === [$a], 'Home Detroit user can work its own exclusive lead');
check($count(['view' => 'all', 'status' => 'any', 'min' => 0], $fourUser) === $poolBefore - 2, "other clients never see Home Detroit's leads");

// bulk status + release
check(Pipeline::bulkStatus([$a, $b], 'dead', $admin) === 2, 'bulk status');
check($count(['view' => 'delivered'], $admin) === 0, 'open filter hides dead leads');
Pipeline::release($b, 'client passed', $admin);
check(Db::val('SELECT exclusive_client_id FROM properties WHERE parcel_id = ?', [$b]) === null, 'release returns lead to pool');
check(Pipeline::events($b)[0]['kind'] === 'released', 'release is on the timeline');

echo PHP_EOL . ($fails ? "$fails check(s) FAILED" : 'All checks passed') . PHP_EOL;
file_put_contents(LEADS_ROOT . '/storage/cache/smoke_parcel.txt', $a);
exit($fails ? 1 : 0);
