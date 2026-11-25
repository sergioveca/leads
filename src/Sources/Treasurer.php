<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Db;
use Leads\Http;
use Leads\Log;

/**
 * County Treasurer tax foreclosure lists (Wayne: "Notice of Forfeited Property Subject to Foreclosure"; Macomb:
 * "Notice of Properties Subject to Foreclosure", see COUNTIES).
 * The PDFs are converted to CSV by tools/parse_treasurer_pdf.py (data/treasurer/*.csv, shipped with the app).
 * - Import: every CSV not yet imported (by file name + size) becomes type=tax_forfeiture signals.
 * - Watch: checks the Treasurer page daily and records when a newer list is published.
 * New lists arrive automatically: .github/workflows/treasurer-list.yml (tools/fetch_treasurer_list.py) commits the CSV.
 */
final class Treasurer
{
    public const JOB = 'treasurer';
    public const PAGE = 'https://www.waynecountymi.gov/Government/Elected-Officials/Treasurer/Property-Tax-Information/Forfeited-Property-List-with-Interested-Parties';
    // The county's Akamai front end answers 403 to browser-like and unknown User-Agents (incl. "Leads/1.0")
    // but allows tool ones (verified 2026-11-24). Same value as tools/fetch_treasurer_list.py.
    private const UA = 'curl/8.5.0 Leads/1.0 (+https://leads.4-return.com)';

    /**
     * County lists in data/treasurer, by file-name prefix: <prefix>_forfeited_<year>.csv.
     * Macomb lists come from the county's December newspaper notice (tools/parse_macomb_notice.py) and carry the
     * amount owed in a 6th column. Macomb parcel ids are BSA ids ("01-13-21-202-024"); DealMachine's APN is the
     * local tax id without the 2-digit community prefix.
     */
    public const COUNTIES = [
        'wayne' => ['county' => 'WAYNE', 'source' => 'Wayne County Treasurer', 'fallback_muni' => 'WAYNE COUNTY'],
        'macomb' => ['county' => 'MACOMB', 'source' => 'Macomb County Treasurer', 'fallback_muni' => 'MACOMB COUNTY'],
    ];

    /** Imports up to $maxRows rows from pending CSVs. Returns rows imported. */
    public static function import(int $maxRows = 20000): int
    {
        $files = glob(LEADS_ROOT . '/data/treasurer/*_forfeited_*.csv') ?: [];
        sort($files);
        $total = 0;
        foreach ($files as $file) {
            $c = self::COUNTIES[strtolower(strstr(basename($file), '_', true))] ?? null;
            if ($c === null) {
                continue;
            }
            $key = 'treasurer_file:' . basename($file);
            $sig = (string)filesize($file);
            $state = Db::setting($key);
            if ($state === 'done:' . $sig) {
                continue;
            }
            $offset = ($state !== null && str_starts_with($state, 'row:')) ? (int)substr($state, 4) : 0;
            $fh = fopen($file, 'rb');
            if (!$fh) {
                continue;
            }
            fgetcsv($fh, 0, ',', '"', '\\'); // header
            $i = 0;
            $now = gmdate('Y-m-d H:i:s');
            $signals = [];
            $props = [];
            $year = (int)preg_replace('/\D/', '', basename($file));
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                if ($i++ < $offset) {
                    continue;
                }
                [$pid, $addr, $muni, $parties, $listYear, $owed] = array_pad($row, 6, '');
                $pid = trim($pid);
                if ($pid === '') {
                    continue;
                }
                $listYear = (int)$listYear ?: $year;
                $owed = trim(str_replace([',', '$'], '', (string)$owed));
                $signals[] = [
                    'parcel_id' => $pid,
                    'type' => 'tax_forfeiture',
                    'source' => $c['source'],
                    'source_ref' => (string)$listYear,
                    'amount' => is_numeric($owed) ? round((float)$owed, 2) : null,
                    'item_count' => null,
                    'event_date' => sprintf('%d-03-31', $listYear),
                    'details' => mb_substr($parties, 0, 2000),
                    'active' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ];
                $props[] = [
                    'parcel_id' => $pid,
                    'county' => $c['county'],
                    'apn' => $c['county'] === 'MACOMB' && preg_match('/^\d{2}-(.+)$/', $pid, $mm) ? $mm[1] : null,
                    'municipality' => mb_substr(trim($muni) !== '' ? strtoupper(trim($muni)) : $c['fallback_muni'], 0, 80),
                    'address' => mb_substr(trim($addr), 0, 190),
                    'first_seen_at' => $now,
                    'updated_at' => $now,
                    'needs_score' => 1,
                ];
                if (count($signals) >= 500) {
                    self::flush($signals, $props);
                    $total += 500;
                    $signals = $props = [];
                    Db::setSetting($key, 'row:' . $i);
                    if ($total >= $maxRows) {
                        fclose($fh);
                        return $total;
                    }
                }
            }
            fclose($fh);
            $total += count($signals);
            self::flush($signals, $props);
            Db::setSetting($key, 'done:' . $sig);
            Log::info('Treasurer list imported: ' . basename($file));
        }
        return $total;
    }

    private static function flush(array $signals, array $props): void
    {
        if (!$signals) {
            return;
        }
        Db::upsert('properties', $props, ['needs_score', 'updated_at']);
        // fill address/municipality only where we have none yet (Detroit parcel file is authoritative)
        Db::upsert('signals', $signals, ['amount', 'details', 'active', 'last_seen_at']);
    }

    /** Daily: look for a newer list on the Treasurer page. Returns a status message. */
    public static function watch(): string
    {
        $r = Http::request('GET', self::PAGE, ['Accept: text/html', 'User-Agent: ' . self::UA], null, 45);
        if ($r['status'] !== 200) {
            return 'Treasurer page HTTP ' . $r['status'];
        }
        preg_match_all('~href="([^"]*?(\d{4})_wayne_county_delinquent_tax_liens[^"]*\.pdf)"~i', $r['body'], $m, PREG_SET_ORDER);
        $years = [];
        foreach ($m as $x) {
            if (stripos($x[1], 'archive') === false) {
                $years[(int)$x[2]] = $x[1];
            }
        }
        if (!$years) {
            return 'No list links found on Treasurer page';
        }
        krsort($years);
        $latestYear = array_key_first($years);
        $have = array_map(static fn($f) => (int)preg_replace('/\D/', '', basename($f)), glob(LEADS_ROOT . '/data/treasurer/wayne_forfeited_*.csv') ?: []);
        Db::setSetting('treasurer_latest_published', (string)$latestYear);
        Db::setSetting('treasurer_latest_url', 'https://www.waynecountymi.gov' . $years[$latestYear]);
        if (!in_array($latestYear, $have, true)) {
            return "NEW LIST PUBLISHED: $latestYear (not loaded yet; the daily GitHub workflow \"Treasurer list\" adds it automatically)";
        }
        return "Latest published list $latestYear is loaded";
    }
}
