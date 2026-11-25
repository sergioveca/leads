<?php
declare(strict_types=1);

namespace Leads;

/**
 * Channel-specific CSV exports for the current Leads filter:
 * - sms():   one row per textable mobile number (wireless, not DNC, not opted out), deduplicated by number.
 *            Leading columns are what sms.4-return.com imports: first_name, last_name, phone, address.
 * - podio(): one row per lead with everything an acquisitions manager needs, including the notes timeline,
 *            for importing into Home Detroit's Podio app.
 */
final class Exports
{
    public const SMS_COLUMNS = ['first_name', 'last_name', 'phone', 'address', 'city', 'state', 'zip', 'phone_type', 'carrier',
        'phone_confidence', 'phone_usage_12m', 'contact_type', 'lives_at_property', 'owner_name', 'owner_mailing_address',
        'owner_mailing_city', 'owner_mailing_state', 'owner_mailing_zip', 'county', 'parcel_id', 'score', 'motivators',
        'est_value', 'equity_pct', 'status', 'other_properties', 'lead_url'];

    public const PODIO_COLUMNS = ['title', 'owner_name', 'contact_name', 'phone_1', 'phone_1_type', 'phone_2', 'phone_2_type',
        'phone_3', 'phone_3_type', 'email_1', 'email_2', 'property_address', 'city', 'state', 'zip', 'county', 'parcel_id',
        'owner_mailing_address', 'absentee', 'score', 'motivators', 'why_this_lead', 'est_value', 'equity_pct', 'equity_amount',
        'loan_balance', 'beds', 'baths', 'sqft', 'year_built', 'property_class', 'status', 'delivered_to', 'delivered_at', 'notes', 'lead_url'];

    /** @return array{0:array<int,array>,1:int} rows (assoc) and number of leads they came from */
    public static function smsRows(array $f, array $user, string $baseUrl, int $limit = 20000): array
    {
        [$where, $params] = LeadQuery::where($f, $user);
        $rows = Db::all(
            "SELECT p.*, c.first_name, c.last_name, c.full_name, c.contact_type, c.is_resident,
                    ph.number, ph.phone_type, ph.carrier, ph.confidence, ph.usage_12m
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
                'first_name' => $first, 'last_name' => $last, 'phone' => $n,
                'address' => (string)$r['address'], 'city' => self::city($r), 'state' => 'MI', 'zip' => (string)($r['zip'] ?? ''),
                'phone_type' => (string)$r['phone_type'], 'carrier' => (string)($r['carrier'] ?? ''),
                'phone_confidence' => $r['confidence'] !== null ? (string)(int)$r['confidence'] : '', 'phone_usage_12m' => (string)($r['usage_12m'] ?? ''),
                'contact_type' => (string)($r['contact_type'] ?? ''), 'lives_at_property' => $r['is_resident'] === null ? '' : ((int)$r['is_resident'] ? 'yes' : 'no'),
                'owner_name' => (string)($r['owner1'] ?? ''), 'owner_mailing_address' => (string)($r['mail_address'] ?? ''),
                'owner_mailing_city' => (string)($r['mail_city'] ?? ''), 'owner_mailing_state' => (string)($r['mail_state'] ?? ''),
                'owner_mailing_zip' => (string)($r['mail_zip'] ?? ''), 'county' => ucfirst(strtolower((string)$r['county'])),
                'parcel_id' => (string)$r['parcel_id'], 'score' => (string)$r['score'], 'motivators' => $motiv[$r['parcel_id']] ?? '',
                'est_value' => (string)($r['dm_estimated_value'] ?? ''), 'equity_pct' => $r['dm_equity_pct'] !== null ? (string)(int)$r['dm_equity_pct'] : '',
                'status' => Pipeline::label($r['status']), 'other_properties' => '',
                'lead_url' => $baseUrl . '/lead?id=' . rawurlencode((string)$r['parcel_id']),
            ];
        }
        return [array_values($byPhone), count($motiv)];
    }

    /** @return array<int,array> one row per lead */
    public static function podioRows(array $f, array $user, string $baseUrl, int $limit = 5000): array
    {
        [$where, $params] = LeadQuery::where($f, $user);
        $props = Db::all(
            "SELECT p.*, xc.name AS exclusive_client FROM properties p LEFT JOIN clients xc ON xc.id = p.exclusive_client_id
             WHERE $where ORDER BY " . LeadQuery::order($f['sort']) . ' LIMIT ' . (int)$limit,
            $params
        );
        $motiv = self::motivatorLabels(array_column($props, 'parcel_id'));
        $out = [];
        foreach ($props as $p) {
            $pid = $p['parcel_id'];
            $contact = Db::one("SELECT * FROM contacts WHERE parcel_id = ? ORDER BY contact_type = 'owner' DESC, id LIMIT 1", [$pid]);
            $phones = Db::all(
                "SELECT ph.number, ph.phone_type FROM contacts c JOIN phones ph ON ph.contact_id = c.id LEFT JOIN opt_outs o ON o.value = ph.number
                 WHERE c.parcel_id = ? AND COALESCE(ph.is_dnc, 0) = 0 AND o.id IS NULL
                 ORDER BY (c.contact_type = 'owner') DESC, (ph.phone_type = 'wireless') DESC, ph.confidence DESC LIMIT 3",
                [$pid]
            );
            $emails = Db::all(
                'SELECT em.address FROM contacts c JOIN emails em ON em.contact_id = c.id LEFT JOIN opt_outs o ON o.value = em.address
                 WHERE c.parcel_id = ? AND o.id IS NULL ORDER BY em.confidence DESC LIMIT 2',
                [$pid]
            );
            $notes = array_map(
                static fn($e) => when($e['created_at'], 'Y-m-d') . ' ' . ($e['kind'] === 'status' ? '[' . Pipeline::label($e['new_status']) . '] ' : '') . trim((string)$e['body']),
                array_reverse(Db::all("SELECT * FROM lead_events WHERE parcel_id = ? AND (kind IN ('note','status')) ORDER BY id DESC LIMIT 30", [$pid]))
            );
            $row = [
                'title' => trim($p['address'] . ', ' . self::city($p), ', '),
                'owner_name' => (string)($p['owner1'] ?? ''),
                'contact_name' => (string)($contact['full_name'] ?? ''),
            ];
            for ($i = 0; $i < 3; $i++) {
                $row['phone_' . ($i + 1)] = isset($phones[$i]) ? phone_fmt($phones[$i]['number']) : '';
                $row['phone_' . ($i + 1) . '_type'] = $phones[$i]['phone_type'] ?? '';
            }
            $row += [
                'email_1' => $emails[0]['address'] ?? '', 'email_2' => $emails[1]['address'] ?? '',
                'property_address' => (string)$p['address'], 'city' => self::city($p), 'state' => 'MI', 'zip' => (string)($p['zip'] ?? ''),
                'county' => ucfirst(strtolower((string)$p['county'])), 'parcel_id' => $pid,
                'owner_mailing_address' => trim(implode(', ', array_filter([$p['mail_address'], $p['mail_city'], trim(($p['mail_state'] ?? '') . ' ' . ($p['mail_zip'] ?? ''))]))),
                'absentee' => ((int)($p['is_absentee'] ?? 0) === 1 || ($p['dm_owner_occupied'] !== null && (int)$p['dm_owner_occupied'] === 0)) ? 'yes' : 'no',
                'score' => (string)$p['score'], 'motivators' => $motiv[$pid] ?? '', 'why_this_lead' => (string)($p['score_reasons'] ?? ''),
                'est_value' => (string)($p['dm_estimated_value'] ?? ''), 'equity_pct' => $p['dm_equity_pct'] !== null ? (string)(int)$p['dm_equity_pct'] : '',
                'equity_amount' => (string)($p['dm_equity_amount'] ?? ''), 'loan_balance' => (string)($p['dm_loan_balance'] ?? ''),
                'beds' => (string)($p['dm_beds'] ?? ''), 'baths' => (string)($p['dm_baths'] ?? ''), 'sqft' => (string)($p['dm_sqft'] ?? $p['building_sqft'] ?? ''),
                'year_built' => (string)($p['year_built'] ?? $p['dm_year_built'] ?? ''), 'property_class' => (string)($p['property_class'] ?? ''),
                'status' => Pipeline::label($p['status']), 'delivered_to' => (string)($p['exclusive_client'] ?? ''),
                'delivered_at' => $p['delivered_at'] ? when($p['delivered_at'], 'Y-m-d') : '',
                'notes' => implode("\n", $notes),
                'lead_url' => $baseUrl . '/lead?id=' . rawurlencode($pid),
            ];
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,string> parcel_id => "Label | Label" */
    private static function motivatorLabels(array $pids): array
    {
        if (!$pids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($pids, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $props = [];
            foreach (Db::all("SELECT * FROM properties WHERE parcel_id IN ($in)", $chunk) as $p) {
                $props[$p['parcel_id']] = $p;
            }
            $sigs = [];
            foreach (Db::all("SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE active = 1 AND parcel_id IN ($in)", $chunk) as $s) {
                $sigs[$s['parcel_id']][] = $s;
            }
            foreach ($props as $pid => $p) {
                $keys = array_diff(Motivators::evaluate($p, $sigs[$pid] ?? []), ['individual']);
                $out[$pid] = implode(' | ', array_map([Motivators::class, 'label'], $keys));
            }
        }
        return $out;
    }

    /** First/last name for a text greeting: DealMachine contact first, else split the owner of record ("LAST, FIRST"). */
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
