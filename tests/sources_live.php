<?php
declare(strict_types=1);

/*
 * Live check of every Detroit signal source against the real ArcGIS API (CI, scratch DB only):
 * imports the first page of each and finishes the pass for sources that fit in one page.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Leads\Db;
use Leads\Sources\DetroitSignals;

if (!str_contains((string)\Leads\Env::get('DB_DATABASE'), 'test')) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must contain 'test'\n");
    exit(2);
}

$fails = 0;
foreach (array_keys(DetroitSignals::sources()) as $key) {
    $t = microtime(true);
    try {
        [$n, $finished] = DetroitSignals::page($key, 0);
        $type = DetroitSignals::sources()[$key]['type'];
        $sample = Db::one('SELECT parcel_id, event_date, item_count, amount, details FROM signals WHERE type = ? ORDER BY event_date DESC LIMIT 1', [$type]);
        printf("ok   %-24s %5d rows%s in %.1fs  e.g. %s\n", $key, $n, $finished ? ' (complete)' : ' (more pages)', microtime(true) - $t, json_encode($sample));
        if ($n === 0 && !in_array($key, ['detroit_demo_contracted', 'detroit_demo_pipeline'], true)) {
            echo "FAIL $key returned no rows\n";
            $fails++;
        }
    } catch (\Throwable $e) {
        echo "FAIL $key: " . $e->getMessage() . "\n";
        $fails++;
    }
}
// Macomb owner sync for the 3 Macomb parcels tests/smoke.php imported
try {
    $n = \Leads\Sources\MacombParcels::batch(150);
    $p = Db::one("SELECT owner1, address, municipality, apn, property_class, assessed_value, is_entity, parcel_synced_at FROM properties WHERE parcel_id = '01-13-21-202-024'");
    $ok = $n >= 3 && $p && $p['parcel_synced_at'] && stripos((string)$p['owner1'], 'LLC') !== false && (int)$p['is_entity'] === 1
        && $p['municipality'] === 'CENTER LINE' && $p['apn'] === '13-21-202-024' && (int)$p['assessed_value'] > 0;
    echo ($ok ? 'ok   ' : 'FAIL ') . "macomb_parcels synced $n: " . json_encode($p) . "\n";
    $fails += $ok ? 0 : 1;
} catch (\Throwable $e) {
    echo 'FAIL macomb_parcels: ' . $e->getMessage() . "\n";
    $fails++;
}
echo $fails ? "$fails source(s) FAILED\n" : "All sources OK\n";
exit($fails ? 1 : 0);
