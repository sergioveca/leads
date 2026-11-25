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
foreach (glob(LEADS_ROOT . '/data/treasurer/*_forfeited_*.csv') as $f) {
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

// ---------------------------------------------------------------- motivators and lists
use Leads\Lists;
use Leads\Motivators;

$now = gmdate('Y-m-d H:i:s');
$today = gmdate('Y-m-d');
$ago = static fn(string $rel): string => gmdate('Y-m-d', strtotime($rel));
// synthetic Detroit parcels covering every signal type, plus edge cases
$fixtures = [
    'T-FIRE.' => [['fire', 'building_fire', $ago('-2 months')]],
    'T-RENT-BAD.' => [['rental', 'registered', $ago('-1 year')], ['blight', 'open', $ago('-1 month')]],
    'T-RENT-OK.' => [['rental', 'registered', $ago('-1 year')], ['rental_cert', 'active', $ago('+1 year')]],
    'T-SHERIFF-NEW.' => [['sheriff_sale', 'sale:1', $ago('-30 days')]],
    'T-SHERIFF-OLD.' => [['sheriff_sale', 'sale:2', $ago('-400 days')]],
    'T-INHERIT.' => [['inherited', 'sale:3', $ago('-1 year')], ['vacant', 'registered', $ago('-2 months')], ['demolition', 'pipeline', null]],
    'T-ESTATE.' => [['blight', 'open', $ago('-3 months')]],
    'T-PENDING.' => [['tax_forfeiture', '2025', '2025-03-31'], ['tax_forfeiture', (string)((int)date('Y') + 1), ((int)date('Y') + 1) . '-03-31']],
];
$owners = [
    'T-ESTATE.' => ['SMITH, JOHN ESTATE OF', null, 0, 0, '1990-05-01'],
    'T-INHERIT.' => ['DOE HEIRS', null, 1, 1, null],
    'T-FIRE.' => ['ACME HOLDINGS LLC', null, 1, 0, '2015-01-01'],
    'T-RENT-BAD.' => ['JONES, MARY', 'REAL ESTATE SOLUTIONS INC', 1, 0, '2001-01-01'],
];
foreach ($fixtures as $pid => $sigs) {
    [$o1, $o2, $abs, $oos, $sale] = $owners[$pid] ?? ['PERSON, A', null, 0, 0, null];
    Db::run(
        "INSERT INTO properties (parcel_id, municipality, address, owner1, owner2, is_absentee, is_out_of_state, is_entity, sale_date, property_class, first_seen_at, updated_at, needs_score)
         VALUES (?, 'DETROIT', ?, ?, ?, ?, ?, ?, ?, 'RESIDENTIAL', ?, ?, 1)",
        [$pid, "1 TEST $pid", $o1, $o2, $abs, $oos, \Leads\Sources\DetroitParcels::isEntity($o1) ? 1 : 0, $sale, $now, $now]
    );
    foreach ($sigs as [$type, $ref, $date]) {
        Db::run(
            "INSERT INTO signals (parcel_id, type, source, source_ref, item_count, event_date, active, first_seen_at, last_seen_at) VALUES (?, ?, 'test', ?, 1, ?, 1, ?, ?)",
            [$pid, $type, $ref, $date, $now, $now]
        );
    }
}
while (Scoring::rescore(5000) > 0) {
}
$ids = array_keys($fixtures);
$in = implode(',', array_fill(0, count($ids), '?'));
$props = [];
foreach (Db::all("SELECT * FROM properties WHERE parcel_id IN ($in)", $ids) as $r) {
    $props[$r['parcel_id']] = $r;
}
$sigRows = [];
foreach (Db::all("SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE active = 1 AND parcel_id IN ($in)", $ids) as $s) {
    $sigRows[$s['parcel_id']][] = $s;
}
$php = [];
foreach ($props as $pid => $r) {
    $php[$pid] = Motivators::evaluate($r, $sigRows[$pid] ?? []);
}
$mismatch = [];
foreach (array_keys(Motivators::all()) as $k) {
    [$sql, $args] = Motivators::sql($k);
    $viaSql = array_column(Db::all("SELECT p.parcel_id FROM properties p WHERE p.parcel_id IN ($in) AND ($sql) ORDER BY p.parcel_id", [...$ids, ...$args]), 'parcel_id');
    $viaPhp = array_keys(array_filter($php, static fn($m) => in_array($k, $m, true)));
    sort($viaPhp);
    if ($viaSql !== $viaPhp) {
        $mismatch[] = "$k sql=[" . implode(',', $viaSql) . '] php=[' . implode(',', $viaPhp) . ']';
    }
}
check(!$mismatch, 'SQL and PHP agree on every motivator' . ($mismatch ? ': ' . implode('; ', $mismatch) : ''));
$expect = [
    'T-FIRE.' => ['fire', 'entity'], 'T-RENT-BAD.' => ['rental_noncompliant', 'landlord', 'absentee', 'long_owned', 'blight'],
    'T-SHERIFF-NEW.' => ['sheriff_sale'], 'T-INHERIT.' => ['inherited', 'vacant', 'demolition', 'estate_owner', 'out_of_state'],
    'T-ESTATE.' => ['estate_owner', 'long_owned'], 'T-PENDING.' => ['tax', 'tax_pending', 'tax_multi'],
];
foreach ($expect as $pid => $want) {
    $missing = array_diff($want, $php[$pid]);
    check(!$missing, "$pid has " . implode(', ', $want) . ($missing ? ' (missing ' . implode(', ', $missing) . ')' : ''));
}
check(!in_array('sheriff_sale', $php['T-SHERIFF-OLD.'], true), 'sheriff sale older than the redemption period is not "in redemption"');
check(!in_array('rental_noncompliant', $php['T-RENT-OK.'], true) && in_array('landlord', $php['T-RENT-OK.'], true), 'rental with a certificate is a landlord, not non-compliant');
check(!in_array('estate_owner', $php['T-RENT-BAD.'], true), '"REAL ESTATE SOLUTIONS INC" is not an estate');
check(str_contains((string)$props['T-SHERIFF-NEW.']['score_reasons'], 'sheriff sale') && (int)$props['T-SHERIFF-NEW.']['score'] >= 40, 'sheriff sale in redemption scores +40');
check(str_contains((string)$props['T-INHERIT.']['score_reasons'], 'distress signals stacked'), 'stacked bonus for 3+ situations');

$cnt = static function (array $in) use ($ids): array {
    $f = LeadQuery::filters($in + ['min' => 0, 'status' => 'any', 'view' => 'all']);
    [$w, $p] = LeadQuery::where($f);
    $inq = implode(',', array_fill(0, count($ids), '?'));
    return array_column(Db::all("SELECT p.parcel_id FROM properties p WHERE $w AND p.parcel_id IN ($inq) ORDER BY p.parcel_id", [...$p, ...$ids]), 'parcel_id');
};
check($cnt(['all' => ['landlord'], 'any' => ['blight', 'fire']]) === ['T-RENT-BAD.'], 'must + any of');
check($cnt(['any' => ['inherited', 'estate_owner'], 'none' => ['vacant']]) === ['T-ESTATE.'], 'any of + exclude');
check($cnt(['m' => ['fire' => 'all']]) === ['T-FIRE.'], 'picker m[key]=all');
check($cnt(['stack' => 3]) === ['T-INHERIT.'], 'stack 3+');
check($cnt(['signal' => 'blight', 'absentee' => '1']) === ['T-RENT-BAD.'], 'old signal/absentee links still work');

$presets = Db::all('SELECT * FROM saved_lists ORDER BY sort_order');
check(count($presets) === 10, '10 starter lists');
$bad = [];
foreach ($presets as $l) {
    try {
        Lists::count($l);
    } catch (\Throwable $e) {
        $bad[] = $l['name'] . ': ' . $e->getMessage();
    }
}
check(!$bad, 'every starter list runs' . ($bad ? ': ' . implode('; ', $bad) : ''));
check(Lists::recount() === 10 && (int)Db::val('SELECT COUNT(*) FROM saved_lists WHERE last_count IS NULL') === 0, 'recount fills counts');
$f = LeadQuery::filters(['all' => ['landlord'], 'any' => ['blight', 'fire'], 'none' => ['entity'], 'stack' => 2, 'min' => 0]);
check(LeadQuery::filters((static function (string $q) { parse_str($q, $o); return $o; })(Lists::query($f))) == $f, 'saved query round-trips');
echo 'list sizes: ' . implode(', ', array_map(static fn($l) => $l['name'] . '=' . Db::val('SELECT last_count FROM saved_lists WHERE id = ?', [$l['id']]), $presets)) . PHP_EOL;

// ---------------------------------------------------------------- select all, auto min score, motivator counts
check(LeadQuery::filters([])['min'] === 50 && LeadQuery::filters(['m' => ['fire' => 'all']])['min'] === 0
    && LeadQuery::filters(['stack' => 3])['min'] === 0 && LeadQuery::filters(['m' => ['fire' => 'all'], 'min' => '40'])['min'] === 40,
    'min score: 50 by default, 0 with motivators, explicit value wins');
$q = http_build_query(['all' => ['landlord'], 'any' => ['blight', 'fire'], 'view' => 'all', 'status' => 'any']);
check(LeadQuery::bulkIds(['scope' => 'all', 'query' => $q], $admin, 100) === ['T-RENT-BAD.'], 'select all matching resolves the filter (auto min 0)');
check(LeadQuery::bulkIds(['scope' => 'selected', 'ids' => ['a', 'b', 'a']], $admin, 1) === ['a'], 'ticked ids are de-duplicated and limited');
check(LeadQuery::bulkIds(['scope' => 'all', 'query' => $q], $admin, 100, 'p.traced_at IS NULL') === ['T-RENT-BAD.'], 'extra condition (untraced) applies');
$mcounts = Lists::countMotivators();
check(($mcounts['fire'] ?? 0) >= 1 && ($mcounts['high_equity'] ?? -1) === 0 && Lists::motivatorCounts()['at'] !== null, 'motivator counts computed and stored');

// ---------------------------------------------------------------- suggested searches
$sugBad = [];
foreach (\Leads\Suggestions::all() as $key => $s) {
    parse_str(\Leads\Suggestions::url($key), $in);
    $f = LeadQuery::filters($in);
    foreach (['all', 'any', 'none'] as $k) {
        if (array_diff($s['query'][$k] ?? [], $f[$k])) {
            $sugBad[] = "$key: unknown motivator in $k";
        }
    }
    if (!isset(\Leads\Suggestions::GROUPS[$s['group']])) {
        $sugBad[] = "$key: unknown group";
    }
}
check(!$sugBad, 'every suggested search uses valid motivators and groups' . ($sugBad ? ': ' . implode('; ', $sugBad) : ''));
$sc = \Leads\Suggestions::countAll();
check(count($sc) === count(\Leads\Suggestions::all()) && ($sc['fire'] ?? 0) === 0 && ($sc['stacked3'] ?? 0) >= 1, 'suggested searches all run and count (fire excludes the company-owned fixture)');
check(LeadQuery::filters(['stack' => 1])['stack'] === 1, 'stack 1+ is allowed');
echo 'suggestion counts: ' . json_encode($sc) . PHP_EOL;

// ---------------------------------------------------------------- SMS export
$addContact = static function (string $pid, string $first, string $last, array $phones) use ($now): void {
    Db::run("INSERT INTO contacts (parcel_id, dm_person_id, full_name, first_name, last_name, contact_type, created_at) VALUES (?, ?, ?, ?, ?, 'owner', ?)",
        [$pid, 'dm-' . $pid, strtoupper("$first $last"), strtoupper($first), strtoupper($last), $now]);
    $cid = (int)Db::pdo()->lastInsertId();
    foreach ($phones as [$num, $type, $dnc]) {
        Db::run('INSERT INTO phones (contact_id, number, phone_type, is_dnc, confidence) VALUES (?, ?, ?, ?, 90)', [$cid, $num, $type, $dnc]);
    }
};
$addContact('T-RENT-BAD.', 'Mary', 'Jones', [['3135550001', 'wireless', 0], ['3135550002', 'wireless', 1], ['3135550003', 'landline', 0], ['3135550004', 'wireless', 0]]);
$addContact('T-ESTATE.', 'Mary', 'Jones', [['3135550001', 'wireless', 0]]); // same person, second property
Db::run("INSERT INTO opt_outs (value, kind, created_at) VALUES ('3135550004', 'phone', UTC_TIMESTAMP())");
Pipeline::update('T-RENT-BAD.', 'contacted', 'Replied YES to text, wants 60k, call after 5pm', $admin);
$fx = LeadQuery::filters(['view' => 'all', 'status' => 'any', 'min' => 0, 'q' => 'TEST T-']);
[$smsRows] = \Leads\Exports::smsRows($fx, $admin, 'https://leads.test');
check(array_column($smsRows, 'phone') === ['3135550001'], 'SMS export: only mobile, not DNC, not opted out, each number once');
check(array_slice(\Leads\Exports::SMS_COLUMNS, 0, 4) === ['first_name', 'last_name', 'phone', 'address'], 'SMS columns start with first_name, last_name, phone, address');
check(($smsRows[0]['first_name'] ?? '') === 'Mary' && ($smsRows[0]['last_name'] ?? '') === 'Jones' && stripos((string)($smsRows[0]['other_properties'] ?? ''), 'TEST') !== false, 'SMS row: proper-case name, other property listed');
Db::run("UPDATE properties SET dm_matched = 1, dm_estimated_value = 85000, dm_equity_pct = 72, dm_loan_balance = 0, dm_beds = 3, dm_baths = 1.5, dm_sqft = 1100, dm_owner_occupied = 0 WHERE parcel_id IN ('T-RENT-BAD.', 'T-ESTATE.')");
[$smsRows] = \Leads\Exports::smsRows($fx, $admin, 'https://leads.test');
$r0 = $smsRows[0] ?? [];
check(($r0['est_value'] ?? '') === '85000' && ($r0['equity_pct'] ?? '') === '72' && ($r0['beds'] ?? '') === '3.0' && ($r0['owner_occupied'] ?? '') === 'no'
    && ($r0['absentee'] ?? '') === 'yes' && str_contains((string)($r0['motivators'] ?? ''), 'Equity 50%+') && str_starts_with((string)($r0['lead_url'] ?? ''), 'https://leads.test/lead?id='),
    'SMS row carries DealMachine property data, motivators and the lead link: ' . json_encode(array_intersect_key($r0, array_flip(['est_value', 'equity_pct', 'beds', 'owner_occupied', 'absentee']))));
check(count(array_diff(\Leads\Exports::SMS_COLUMNS, array_keys($r0))) === 0, 'every SMS column is filled from the row');
check(\Leads\Exports::fullAddress(['address' => '7296 FENTON ST', 'municipality' => 'DEARBORN HEIGHTS', 'zip' => '48127-1626']) === '7296 Fenton St, Dearborn Heights, MI 48127'
    && \Leads\Exports::fullAddress(['address' => '100 W SEVEN MILE', 'municipality' => 'WAYNE COUNTY', 'zip' => null]) === '100 W Seven Mile, MI', 'SMS address is the full address');
check(preg_match('/^.+, MI( \d{5})?$/', (string)($r0['address'] ?? '')) === 1, 'SMS row address: ' . ($r0['address'] ?? ''));
// DealMachine's street (with St/Ave/Rd) wins over the county's
Db::run("UPDATE properties SET dm_address = '618 Gladstone St', dm_city = 'Detroit', dm_zip = '48202' WHERE parcel_id IN ('T-RENT-BAD.', 'T-ESTATE.')");
[$smsRows] = \Leads\Exports::smsRows($fx, $admin, 'https://leads.test');
check(($smsRows[0]['address'] ?? '') === '618 Gladstone St, Detroit, MI 48202', 'SMS address uses DealMachine street: ' . ($smsRows[0]['address'] ?? ''));
check(\Leads\Exports::fullAddress(['address' => '618 GLADSTONE', 'municipality' => 'DETROIT', 'zip' => '48202', 'dm_address' => '', 'dm_city' => null]) === '618 Gladstone, Detroit, MI 48202', 'empty DealMachine address falls back to the county');
check(isset(\Leads\Jobs::pending()['dm_address_backfill']) === false, 'no address backfill without a DealMachine key');

// ---------------------------------------------------------------- Macomb county list
// real Macomb BSA ids (from the Dec 2025 notice); tests/sources_live.php syncs their owners from the county layer
$mfile = LEADS_ROOT . '/data/treasurer/macomb_forfeited_2099.csv';
file_put_contents($mfile, "parcel_id,address,municipality,interested_parties,list_year,amount_owed\n"
    . "01-13-21-202-024,26673 LAWRENCE,CENTER LINE,26673 LAWRENCE AVE CENTER LINE LLC,2099,22832.28\n"
    . "01-13-21-202-025,26641 LAWRENCE,CENTER LINE,FRANKLIN EUPHEMIA; THE HUNTINGTON NATIONAL BANK,2099,\"10,736.83\"\n"
    . "01-13-21-202-026,LAWRENCE,CENTER LINE,SOMEONE,2099,2619.06\n");
try {
    check(Treasurer::import() === 3, 'Macomb CSV imports (3 rows)');
} finally {
    @unlink($mfile);
}
$mp = Db::one("SELECT * FROM properties WHERE parcel_id = '01-13-21-202-025'");
check($mp && $mp['county'] === 'MACOMB' && $mp['apn'] === '13-21-202-025' && $mp['municipality'] === 'CENTER LINE', 'Macomb property: county, DealMachine APN without community prefix, city');
check((float)Db::val("SELECT amount FROM signals WHERE parcel_id = '01-13-21-202-025' AND type = 'tax_forfeiture'") === 10736.83, 'amount owed stored ("10,736.83")');
check((int)Db::val("SELECT COUNT(*) FROM properties WHERE county = 'WAYNE'") === (int)Db::val("SELECT COUNT(*) FROM properties WHERE county <> 'MACOMB'"), 'existing properties default to Wayne');
while (Scoring::rescore(5000) > 0) {
}
$ms = Db::all("SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE parcel_id = '01-13-21-202-025' AND active = 1");
check(in_array('tax_big', Motivators::evaluate($mp, $ms), true) && in_array('tax_pending', Motivators::evaluate($mp, $ms), true), 'owes $5k+ and pending (2099 list)');
check(str_contains((string)Db::val("SELECT score_reasons FROM properties WHERE parcel_id = '01-13-21-202-025'"), 'Owes $10,737 in back taxes'), 'score explains the amount owed');
$mq = static fn(array $in) => array_column(Db::all('SELECT p.parcel_id FROM properties p WHERE ' . LeadQuery::where(LeadQuery::filters($in + ['view' => 'all', 'status' => 'any']))[0] . ' ORDER BY p.parcel_id', LeadQuery::where(LeadQuery::filters($in + ['view' => 'all', 'status' => 'any']))[1]), 'parcel_id');
// the real 2026 Macomb list is also loaded, so check the fixtures by inclusion
$fxIds = ['01-13-21-202-024', '01-13-21-202-025', '01-13-21-202-026'];
$macomb = $mq(['county' => 'macomb', 'min' => 0]);
check(!array_diff($fxIds, $macomb) && (int)Db::val("SELECT COUNT(*) FROM properties WHERE county = 'MACOMB'") === count($macomb) + (int)Db::val("SELECT COUNT(*) FROM properties WHERE county = 'MACOMB' AND is_excluded = 1"), 'county filter (' . count($macomb) . ' Macomb leads)');
$bigs = array_intersect($fxIds, $mq(['county' => 'MACOMB', 'm' => ['tax_big' => 'all']]));
check(array_values($bigs) === ['01-13-21-202-024', '01-13-21-202-025'], '"owes $5k+" motivator');
[$sqlBig, $argsBig] = Motivators::sql('tax_big');
check((int)Db::val("SELECT COUNT(*) FROM properties p WHERE p.county = 'WAYNE' AND ($sqlBig)", $argsBig) === 0, 'Wayne lists have no amounts, so never "owes $5k+"');
check(\Leads\Sources\MacombParcels::propertyClass('V-R1') === 'RESIDENTIAL (zoning V-R1)' && str_starts_with((string)\Leads\Sources\MacombParcels::propertyClass('M-1'), 'COMMERCIAL')
    && \Leads\Sources\MacombParcels::community('SAINT CLAIR SHORES') === 'ST CLAIR SHORES', 'Macomb zoning and community names normalized');

echo PHP_EOL . ($fails ? "$fails check(s) FAILED" : 'All checks passed') . PHP_EOL;
file_put_contents(LEADS_ROOT . '/storage/cache/smoke_parcel.txt', $a);
exit($fails ? 1 : 0);
