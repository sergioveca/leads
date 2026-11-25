<?php
declare(strict_types=1);

namespace Leads;

/**
 * "Export for SMS": one row per textable mobile number for the current Leads filter (wireless, not DNC, not opted
 * out), deduplicated by number. The first four columns are what sms.4-return.com imports
 * (first_name, last_name, phone, address); the rest is lead and DealMachine property data for the conversation.
 */
final class Exports
{
    public const SMS_COLUMNS = [
        'first_name', 'last_name', 'phone', 'address',
        'city', 'state', 'zip', 'county',
        'phone_type', 'carrier', 'phone_confidence', 'phone_usage_12m', 'contact_type', 'lives_at_property',
        'owner_name', 'owner_mailing_address', 'owner_mailing_city', 'owner_mailing_state', 'owner_mailing_zip', 'absentee',
        // DealMachine property data (after skip trace)
        'est_value', 'equity_pct', 'equity_amount', 'loan_balance', 'owner_occupied', 'beds', 'baths', 'sqft', 'year_built', 'annual_tax',
        // public record + Leads
        'property_class', 'last_sale_date', 'last_sale_price', 'back_taxes_owed',
        'parcel_id', 'score', 'motivators', 'why_this_lead', 'status', 'other_properties', 'lead_url',
    ];

    /** @return array{0:array<int,array>,1:int} rows (assoc) and number of leads they came from */
    public static function smsRows(array $f, array $user, string $baseUrl, int $limit = 20000): array
    {
        [$where, $params] = LeadQuery::where($f, $user);
        $rows = Db::all(
            "SELECT p.*, c.first_name, c.last_name, c.full_name, c.contact_type, c.is_resident,
                    ph.number, ph.phone_type, ph.carrier, ph.confidence, ph.usage_12m,
                    (SELECT MAX(s.amount) FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = 'tax_forfeiture') AS back_taxes
             FROM properties p
             JOIN contacts c ON c.parcel_id = p.parcel_id
             JOIN phones ph ON ph.contact_id = c.id
             LEFT JOIN opt_outs o ON o.value = ph.number
             WHERE $where AND ph.phone_type = 'wireless' AND COALESCE(ph.is_dnc, 0) = 0 AND o.id IS NULL
             ORDER BY " . LeadQuery::order($f['sort']) . ", (c.contact_type = 'owner') DESC, ph.confidence DESC
             LIMIT " . (int)$limit,
            $params
        );
        $motiv = self::motivatorLabels(array_values(array_unique(array_column($rows, 'parcel_id'))));
        $val = static fn($v): string => $v === null ? '' : (string)$v;
        $byPhone = [];
        foreach ($rows as $r) {
            $n = (string)$r['number'];
            if (isset($byPhone[$n])) {
                // same person owns several leads: one text, list the other addresses
                if ($r['parcel_id'] !== $byPhone[$n]['parcel_id'] && !str_contains($byPhone[$n]['other_properties'], (string)$r['address'])) {
                    $byPhone[$n]['other_properties'] = ltrim($byPhone[$n]['other_properties'] . '; ' . $r['address'], '; ');
                }
                continue;
            }
            [$first, $last] = self::names($r);
            $byPhone[$n] = [
                'first_name' => $first, 'last_name' => $last, 'phone' => $n, 'address' => (string)$r['address'],
                'city' => self::city($r), 'state' => 'MI', 'zip' => $val($r['zip']), 'county' => ucfirst(strtolower((string)$r['county'])),
                'phone_type' => (string)$r['phone_type'], 'carrier' => $val($r['carrier']),
                'phone_confidence' => $r['confidence'] !== null ? (string)(int)$r['confidence'] : '', 'phone_usage_12m' => $val($r['usage_12m']),
                'contact_type' => $val($r['contact_type']), 'lives_at_property' => $r['is_resident'] === null ? '' : ((int)$r['is_resident'] ? 'yes' : 'no'),
                'owner_name' => $val($r['owner1']), 'owner_mailing_address' => $val($r['mail_address']),
                'owner_mailing_city' => $val($r['mail_city']), 'owner_mailing_state' => $val($r['mail_state']), 'owner_mailing_zip' => $val($r['mail_zip']),
                'absentee' => ((int)($r['is_absentee'] ?? 0) === 1 || ($r['dm_owner_occupied'] !== null && (int)$r['dm_owner_occupied'] === 0)) ? 'yes' : '',
                'est_value' => $val($r['dm_estimated_value']), 'equity_pct' => $r['dm_equity_pct'] !== null ? (string)(int)$r['dm_equity_pct'] : '',
                'equity_amount' => $val($r['dm_equity_amount']), 'loan_balance' => $val($r['dm_loan_balance']),
                'owner_occupied' => $r['dm_owner_occupied'] === null ? '' : ((int)$r['dm_owner_occupied'] ? 'yes' : 'no'),
                'beds' => $val($r['dm_beds']), 'baths' => $val($r['dm_baths']), 'sqft' => $val($r['dm_sqft'] ?? $r['building_sqft']),
                'year_built' => $val($r['dm_year_built'] ?? $r['year_built']), 'annual_tax' => $val($r['dm_annual_tax']),
                'property_class' => $val($r['property_class']), 'last_sale_date' => $val($r['sale_date']), 'last_sale_price' => $val($r['sale_price']),
                'back_taxes_owed' => $r['back_taxes'] !== null ? number_format((float)$r['back_taxes'], 2, '.', '') : '',
                'parcel_id' => (string)$r['parcel_id'], 'score' => (string)$r['score'], 'motivators' => $motiv[$r['parcel_id']] ?? '',
                'why_this_lead' => $val($r['score_reasons']), 'status' => Pipeline::label($r['status']), 'other_properties' => '',
                'lead_url' => $baseUrl . '/lead?id=' . rawurlencode((string)$r['parcel_id']),
            ];
        }
        return [array_values($byPhone), count($motiv)];
    }

    /** @return array<string,string> parcel_id => "Label | Label" */
    private static function motivatorLabels(array $pids): array
    {
        $out = [];
        foreach (array_chunk($pids, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sigs = [];
            foreach (Db::all("SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE active = 1 AND parcel_id IN ($in)", $chunk) as $s) {
                $sigs[$s['parcel_id']][] = $s;
            }
            foreach (Db::all("SELECT * FROM properties WHERE parcel_id IN ($in)", $chunk) as $p) {
                $keys = array_diff(Motivators::evaluate($p, $sigs[$p['parcel_id']] ?? []), ['individual']);
                $out[$p['parcel_id']] = implode(' | ', array_map([Motivators::class, 'label'], $keys));
            }
        }
        return $out;
    }

    /** First/last name for a text greeting: DealMachine contact first, else split its full name. */
    private static function names(array $r): array
    {
        $first = trim((string)($r['first_name'] ?? ''));
        $last = trim((string)($r['last_name'] ?? ''));
        if ($first === '' && !empty($r['full_name'])) {
            $parts = preg_split('/\s+/', trim((string)$r['full_name'])) ?: [];
            $first = (string)array_shift($parts);
            $last = $last ?: implode(' ', $parts);
        }
        $nice = static fn(string $s): string => $s === strtoupper($s) ? ucwords(strtolower($s), " -'") : $s;
        return [$nice($first), $nice($last)];
    }

    private static function city(array $p): string
    {
        $m = (string)($p['municipality'] ?? '');
        return str_ends_with($m, ' COUNTY') ? '' : ucwords(strtolower($m));
    }

    /** Streams rows as a CSV download. */
    public static function send(string $filename, array $columns, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, $columns, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn($c) => $r[$c] ?? '', $columns), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }
}
