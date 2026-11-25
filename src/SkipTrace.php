<?php
declare(strict_types=1);

namespace Leads;

/**
 * Skip tracing through DealMachine: parcel number (APN) first, then address fallback for misses.
 * Misses and repeat lookups cost 0 credits on DealMachine's side; we still never re-trace a parcel
 * traced in the last 90 days unless forced.
 */
final class SkipTrace
{
    /** Returns [apiKey, clientIdUsed] for a client: its own key if set, else 4Return's default key. */
    public static function keyFor(?int $clientId): array
    {
        if ($clientId) {
            $enc = Db::val('SELECT dm_api_key_enc FROM clients WHERE id = ?', [$clientId]);
            $own = Crypto::decrypt($enc ? (string)$enc : null);
            if ($own) {
                return [$own, $clientId];
            }
        }
        $default = (string)Env::get('DEALMACHINE_API_KEY', '');
        if ($default === '') {
            throw new \RuntimeException('No DealMachine API key configured (DEALMACHINE_API_KEY in .env).');
        }
        $fourReturn = (int)Db::val("SELECT id FROM clients WHERE name = '4Return'");
        return [$default, $fourReturn ?: null];
    }

    public static function creditsUsedThisMonth(?int $clientId = null): int
    {
        $sql = "SELECT COALESCE(SUM(credits_used),0) FROM skiptrace_runs WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
        }
        return (int)Db::val($sql, $params);
    }

    public static function monthlyCap(?int $clientId): int
    {
        if ($clientId) {
            $c = Db::val('SELECT monthly_credit_cap FROM clients WHERE id = ?', [$clientId]);
            if ($c !== null) {
                return (int)$c;
            }
        }
        return (int)(Db::setting('monthly_credit_cap') ?? Env::int('DEALMACHINE_MONTHLY_CREDIT_CAP', 15000));
    }

    /**
     * @param string[] $parcelIds
     * @return array{submitted:int, matched:int, credits:int, message:string}
     */
    public static function trace(array $parcelIds, ?int $clientId, ?int $userId, string $trigger, bool $force = false): array
    {
        [$key, $creditClient] = self::keyFor($clientId);
        $parcelIds = array_values(array_unique(array_filter($parcelIds)));
        if (!$force && $parcelIds) {
            $in = implode(',', array_fill(0, count($parcelIds), '?'));
            $recent = array_column(Db::all("SELECT parcel_id FROM properties WHERE parcel_id IN ($in) AND traced_at > UTC_TIMESTAMP() - INTERVAL 90 DAY", $parcelIds), 'parcel_id');
            $parcelIds = array_values(array_diff($parcelIds, $recent));
        }
        if (!$parcelIds) {
            return ['submitted' => 0, 'matched' => 0, 'credits' => 0, 'message' => 'Nothing to trace (already traced recently)'];
        }
        $cap = self::monthlyCap($creditClient);
        $used = self::creditsUsedThisMonth($creditClient);
        // ~2 credits per matched parcel (1 property + 1 owner); keep a margin
        $room = intdiv(max(0, $cap - $used), 3);
        if ($room <= 0) {
            return ['submitted' => 0, 'matched' => 0, 'credits' => 0, 'message' => "Monthly credit cap reached ($used / $cap)"];
        }
        $parcelIds = array_slice($parcelIds, 0, min($room, 250));

        $dm = new DealMachine($key);
        $props = [];
        $in = implode(',', array_fill(0, count($parcelIds), '?'));
        foreach (Db::all("SELECT parcel_id, address, municipality, zip FROM properties WHERE parcel_id IN ($in)", $parcelIds) as $p) {
            $props[$p['parcel_id']] = $p;
        }
        if (!$props) {
            return ['submitted' => 0, 'matched' => 0, 'credits' => 0, 'message' => 'Nothing to trace (unknown parcels)'];
        }

        $matched = 0;
        $credits = 0;
        $byApn = $dm->enrichByApn(array_keys($props));
        $credits += (int)($byApn['credits']['used'] ?? 0);
        $misses = [];
        foreach ($byApn['data'] ?? [] as $i => $res) {
            $apn = (string)($res['input']['apn'] ?? array_keys($props)[$i] ?? '');
            $pid = isset($props[$apn]) ? $apn : (array_keys($props)[$i] ?? null);
            if ($pid === null) {
                continue;
            }
            if (!empty($res['matched'])) {
                self::store($pid, $res, $creditClient);
                $matched++;
            } else {
                $misses[] = $pid;
            }
        }

        // address fallback
        $addrMap = [];
        foreach ($misses as $pid) {
            $p = $props[$pid];
            if (trim((string)$p['address']) === '' || str_starts_with(trim((string)$p['address']), '0 ')) {
                continue;
            }
            $city = $p['municipality'] && $p['municipality'] !== 'WAYNE COUNTY' ? $p['municipality'] : '';
            $full = trim($p['address']) . ($city ? ', ' . ucwords(strtolower($city)) : '') . ', MI' . ($p['zip'] ? ' ' . $p['zip'] : '');
            $addrMap[$full] = $pid;
        }
        $stillMissing = array_flip($misses);
        if ($addrMap) {
            $byAddr = $dm->enrichByAddress(array_keys($addrMap));
            $credits += (int)($byAddr['credits']['used'] ?? 0);
            $keys = array_keys($addrMap);
            foreach ($byAddr['data'] ?? [] as $i => $res) {
                $full = (string)($res['input']['full_address'] ?? $keys[$i] ?? '');
                $pid = $addrMap[$full] ?? ($addrMap[$keys[$i]] ?? null);
                if ($pid !== null && !empty($res['matched'])) {
                    self::store($pid, $res, $creditClient);
                    unset($stillMissing[$pid]);
                    $matched++;
                }
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        // anything submitted that came back neither matched nor as a miss (absent from the response) also counts
        // as tried, so "trace all" never resubmits the same parcel in a loop
        $matchedIds = array_column(Db::all(
            'SELECT parcel_id FROM properties WHERE parcel_id IN (' . implode(',', array_fill(0, count($props), '?')) . ') AND dm_matched = 1 AND traced_at >= ?',
            [...array_keys($props), gmdate('Y-m-d H:i:s', time() - 3600)]
        ), 'parcel_id');
        foreach (array_diff(array_keys($props), $matchedIds, array_keys($stillMissing)) as $pid) {
            $stillMissing[$pid] = true;
        }
        foreach (array_keys($stillMissing) as $pid) {
            Db::run('UPDATE properties SET traced_at = ?, traced_by_client_id = ?, dm_matched = 0 WHERE parcel_id = ?', [$now, $creditClient, $pid]);
        }
        $msg = sprintf('%d submitted, %d matched, %d credits', count($props), $matched, $credits);
        Db::run(
            'INSERT INTO skiptrace_runs (client_id, user_id, trigger_type, submitted, matched, credits_used, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$creditClient, $userId, $trigger, count($props), $matched, $credits, $msg]
        );
        return ['submitted' => count($props), 'matched' => $matched, 'credits' => $credits, 'message' => $msg];
    }

    private static function store(string $pid, array $r, ?int $clientId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $int = static fn($v) => ($v === null || $v === '') ? null : (int)round((float)$v);
        Db::run(
            'UPDATE properties SET dm_property_id = ?, dm_estimated_value = ?, dm_equity_amount = ?, dm_equity_pct = ?, dm_loan_balance = ?, dm_owner_occupied = ?,
             dm_beds = ?, dm_baths = ?, dm_sqft = ?, dm_year_built = ?, dm_annual_tax = ?, dm_matched = 1, traced_at = ?, traced_by_client_id = ?, needs_score = 1,
             address = IF(address = \'\', ?, address), zip = COALESCE(zip, ?)
             WHERE parcel_id = ?',
            [
                $r['dm_property_id'] ?? null, $int($r['estimated_value'] ?? null), $int($r['estimated_equity_amount'] ?? null),
                isset($r['estimated_equity_percentage']) ? round((float)$r['estimated_equity_percentage'], 2) : null,
                $int($r['total_estimated_loan_balance'] ?? null),
                isset($r['owner_occupied']) ? ((bool)$r['owner_occupied'] ? 1 : 0) : null,
                $r['num_bedrooms'] ?? null, $r['num_bathrooms'] ?? null, $int($r['living_area_sqft'] ?? null), $int($r['year_built'] ?? null),
                $int($r['annual_property_tax_amount'] ?? null), $now, $clientId,
                mb_substr((string)($r['address'] ?? ''), 0, 190), $r['zip'] ?? null, $pid,
            ]
        );
        foreach ($r['contacts'] ?? [] as $c) {
            $res = $c['residence'] ?? [];
            Db::run(
                'INSERT INTO contacts (parcel_id, dm_person_id, full_name, first_name, last_name, contact_type, is_resident, res_address, res_city, res_state, res_zip, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), first_name = VALUES(first_name), last_name = VALUES(last_name), contact_type = VALUES(contact_type),
                 is_resident = VALUES(is_resident), res_address = VALUES(res_address), res_city = VALUES(res_city), res_state = VALUES(res_state), res_zip = VALUES(res_zip),
                 id = LAST_INSERT_ID(id)',
                [
                    $pid, $c['dm_person_id'] ?? null, self::name($c), $c['first_name'] ?? null, $c['last_name'] ?? null, $c['contact_type'] ?? null,
                    isset($c['is_resident']) ? ((bool)$c['is_resident'] ? 1 : 0) : null,
                    $res['address'] ?? null, $res['city'] ?? null, $res['state'] ?? null, $res['zip'] ?? null, $now,
                ]
            );
            $cid = (int)Db::pdo()->lastInsertId();
            foreach ($c['phones'] ?? [] as $ph) {
                $num = preg_replace('/\D/', '', (string)($ph['number'] ?? '')) ?? '';
                if (strlen($num) === 11 && $num[0] === '1') {
                    $num = substr($num, 1);
                }
                if (strlen($num) !== 10) {
                    continue;
                }
                Db::run(
                    'INSERT INTO phones (contact_id, number, phone_type, carrier, is_dnc, confidence, usage_2m, usage_12m, dnc_checked_at) VALUES (?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE phone_type = VALUES(phone_type), carrier = VALUES(carrier), is_dnc = VALUES(is_dnc), confidence = VALUES(confidence),
                     usage_2m = VALUES(usage_2m), usage_12m = VALUES(usage_12m), dnc_checked_at = VALUES(dnc_checked_at)',
                    [
                        $cid, $num, $ph['type'] ?? null, $ph['carrier'] ?? null,
                        array_key_exists('do_not_call', $ph) ? ((bool)$ph['do_not_call'] ? 1 : 0) : null,
                        $ph['match_confidence'] ?? null, $ph['usage_2_months'] ?? null, $ph['usage_12_months'] ?? null, $now,
                    ]
                );
            }
            foreach ($c['emails'] ?? [] as $em) {
                $addr = strtolower(trim((string)($em['address'] ?? '')));
                if ($addr === '' || !str_contains($addr, '@')) {
                    continue;
                }
                Db::run(
                    'INSERT INTO emails (contact_id, address, confidence, verification) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), verification = VALUES(verification)',
                    [$cid, $addr, $em['match_confidence'] ?? null, $em['verification_status'] ?? null]
                );
            }
        }
    }

    private static function name(array $c): ?string
    {
        $n = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        return $n !== '' ? $n : ($c['full_name'] ?? null);
    }

    /** Re-check DNC status for phones not checked in 30 days (up to 1,000 per call). */
    public static function recheckDnc(int $limit = 1000): int
    {
        $rows = Db::all('SELECT DISTINCT number FROM phones WHERE dnc_checked_at IS NULL OR dnc_checked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT ' . (int)$limit);
        if (!$rows) {
            return 0;
        }
        [$key] = self::keyFor(null);
        $res = (new DealMachine($key))->checkDnc(array_column($rows, 'number'));
        $now = gmdate('Y-m-d H:i:s');
        foreach ($res['data'] ?? [] as $d) {
            $num = preg_replace('/\D/', '', (string)($d['input']['number'] ?? '')) ?? '';
            if (strlen($num) === 11) {
                $num = substr($num, 1);
            }
            if (!empty($d['matched'])) {
                Db::run('UPDATE phones SET is_dnc = ?, phone_type = COALESCE(?, phone_type), dnc_checked_at = ? WHERE number = ?', [!empty($d['do_not_call']) ? 1 : 0, $d['phone_type'] ?? null, $now, $num]);
            } else {
                Db::run('UPDATE phones SET dnc_checked_at = ? WHERE number = ?', [$now, $num]);
            }
        }
        return count($rows);
    }

    /** Adds traced properties to DealMachine as prospects (client's own key if they have one). */
    public static function pushToDealMachine(array $parcelIds, ?int $clientId): string
    {
        if (!$parcelIds) {
            return 'Nothing selected';
        }
        [$key] = self::keyFor($clientId);
        $in = implode(',', array_fill(0, count($parcelIds), '?'));
        $rows = Db::all("SELECT parcel_id, dm_property_id FROM properties WHERE parcel_id IN ($in) AND dm_property_id IS NOT NULL", $parcelIds);
        if (!$rows) {
            return 'None of the selected leads are traced yet (trace them first)';
        }
        $res = (new DealMachine($key))->addProspects(array_column($rows, 'dm_property_id'));
        Db::run("UPDATE properties SET pushed_to_dm_at = UTC_TIMESTAMP() WHERE parcel_id IN ($in)", $parcelIds);
        $d = $res['data'] ?? [];
        return sprintf('Sent to DealMachine: %d new, %d already there, %d reactivated', (int)($d['created'] ?? 0), (int)($d['existing'] ?? 0), (int)($d['reactivated'] ?? 0));
    }
}
