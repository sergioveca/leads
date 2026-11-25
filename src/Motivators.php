<?php
declare(strict_types=1);

namespace Leads;

/**
 * Seller motivators: the building blocks of lists. Each has a SQL condition on `properties p`
 * (used by the list builder: must have / any of / exclude) and a PHP test on a property row plus its
 * active signals (used for scoring, chips and exports). tests/smoke.php checks that both agree.
 */
final class Motivators
{
    public const GROUPS = [
        'distress' => 'Distress',
        'life' => 'Life events',
        'owner' => 'Owner',
        'money' => 'Equity (after skip trace)',
    ];

    /** Back taxes owed (amount to redeem) that count as "big". */
    public const BIG_TAX = 5000;

    /** Michigan's usual redemption period after a mortgage sheriff's sale (6 months), in days. */
    public const REDEMPTION_DAYS = 183;

    /** Owner names that indicate an estate / heirs (no word-boundary syntax so MariaDB and PHP agree). */
    public const ESTATE_RE = '(^|[^A-Z])(ESTATE OF|HEIRS|DECEASED|DECD)([^A-Z]|$)|[(] ?ESTATE ?[)]|ESTATE$';

    /** @return array<string, array{label:string, group:string, hint:string, sql:string, params?:array}> */
    public static function all(): array
    {
        static $m = null;
        if ($m !== null) {
            return $m;
        }
        $sig = static fn(string $type, string $extra = ''): string =>
            "EXISTS (SELECT 1 FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = '$type'$extra)";
        return $m = [
            'tax_pending' => ['label' => 'Tax foreclosure pending', 'group' => 'distress', 'hint' => "On the current Treasurer list; loses the house on March 31 unless they pay",
                'sql' => $sig('tax_forfeiture', ' AND s.event_date >= CURDATE()')],
            'tax' => ['label' => 'Tax foreclosure list (any year)', 'group' => 'distress', 'hint' => 'On any Wayne County forfeiture list Leads has loaded',
                'sql' => $sig('tax_forfeiture')],
            'tax_multi' => ['label' => 'Tax list 2+ years', 'group' => 'distress', 'hint' => 'Forfeited in more than one year',
                'sql' => "(SELECT COUNT(DISTINCT s.source_ref) FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = 'tax_forfeiture') >= 2"],
            'tax_big' => ['label' => 'Owes $5k+ in back taxes', 'group' => 'distress', 'hint' => 'Amount to redeem is $5,000 or more (Macomb notices list the amount; Wayne lists do not)',
                'sql' => $sig('tax_forfeiture', ' AND s.amount >= ' . self::BIG_TAX)],
            'sheriff_sale' => ['label' => 'Mortgage foreclosure, in redemption', 'group' => 'distress', 'hint' => "Sheriff's sale in the last 6 months: owner can still sell or redeem",
                'sql' => $sig('sheriff_sale', ' AND s.event_date >= CURDATE() - INTERVAL ' . self::REDEMPTION_DAYS . ' DAY')],
            'blight' => ['label' => 'Open blight tickets', 'group' => 'distress', 'hint' => 'Unpaid Detroit blight tickets (3 years)',
                'sql' => $sig('blight')],
            'fire' => ['label' => 'Building fire', 'group' => 'distress', 'hint' => 'Detroit Fire Dept building fire in the last 3 years',
                'sql' => $sig('fire')],
            'vacant' => ['label' => 'Registered vacant', 'group' => 'distress', 'hint' => 'On the Detroit vacant property registry',
                'sql' => $sig('vacant')],
            'demolition' => ['label' => 'Demolition list', 'group' => 'distress', 'hint' => 'In the city demolition pipeline or under contract',
                'sql' => $sig('demolition')],
            'rental_noncompliant' => ['label' => 'Rental without compliance cert', 'group' => 'distress', 'hint' => 'Registered rental with no active certificate of compliance (city enforcement, escrow)',
                'sql' => $sig('rental') . ' AND NOT ' . $sig('rental_cert')],
            'inherited' => ['label' => 'Inherited (estate transfer)', 'group' => 'life', 'hint' => 'Deeded out of an estate / by death certificate in the last 3 years',
                'sql' => $sig('inherited')],
            'estate_owner' => ['label' => 'Owner is an estate / heirs', 'group' => 'life', 'hint' => 'Owner of record reads "Estate of", "Heirs", "Deceased"…',
                'sql' => '(p.owner1 REGEXP ? OR p.owner2 REGEXP ?)', 'params' => [self::ESTATE_RE, self::ESTATE_RE]],
            'absentee' => ['label' => 'Absentee owner', 'group' => 'owner', 'hint' => 'Tax bill mailed somewhere else (or DealMachine: not owner-occupied)',
                'sql' => '(p.is_absentee = 1 OR p.dm_owner_occupied = 0)'],
            'out_of_state' => ['label' => 'Out-of-state owner', 'group' => 'owner', 'hint' => 'Mailing address outside Michigan',
                'sql' => 'p.is_out_of_state = 1'],
            'landlord' => ['label' => 'Registered rental (landlord)', 'group' => 'owner', 'hint' => 'On the Detroit rental registry',
                'sql' => $sig('rental')],
            'long_owned' => ['label' => 'Owned 20+ years', 'group' => 'owner', 'hint' => 'Last sale 20+ years ago: likely high equity, often older owners',
                'sql' => 'p.sale_date <= CURDATE() - INTERVAL 20 YEAR'],
            'individual' => ['label' => 'Individual owner', 'group' => 'owner', 'hint' => 'Not an LLC, company, trust, bank or church',
                'sql' => 'COALESCE(p.is_entity, 0) = 0'],
            'entity' => ['label' => 'Company / trust owner', 'group' => 'owner', 'hint' => 'LLC, company, trust, bank, church…',
                'sql' => 'p.is_entity = 1'],
            'high_equity' => ['label' => 'Equity 50%+', 'group' => 'money', 'hint' => 'DealMachine estimate; only for traced leads',
                'sql' => 'p.dm_equity_pct >= 50'],
            'free_clear' => ['label' => 'Free and clear', 'group' => 'money', 'hint' => 'DealMachine: no mortgage balance',
                'sql' => '(p.dm_matched = 1 AND p.dm_loan_balance = 0)'],
        ];
    }

    /** Display name of a raw signal row (lead page). */
    public static function signalLabel(array $s): string
    {
        return match ($s['type']) {
            'tax_forfeiture' => 'Tax foreclosure list ' . $s['source_ref'],
            'blight' => 'Blight tickets',
            'fire' => 'Building fire',
            'vacant' => 'Registered vacant',
            'demolition' => $s['source_ref'] === 'contracted' ? 'Demolition under contract' : 'Demolition pipeline',
            'rental' => 'Registered rental',
            'rental_cert' => 'Certificate of compliance',
            'sheriff_sale' => "Mortgage foreclosure (sheriff's deed)",
            'inherited' => 'Estate transfer',
            default => (string)$s['type'],
        };
    }

    public static function signalDetail(array $s): string
    {
        $n = (int)($s['item_count'] ?? 0);
        return match ($s['type']) {
            'tax_forfeiture' => ($s['amount'] !== null ? 'Owes ' . money($s['amount']) . ' to redeem (as of Dec 1). ' : '')
                . 'Interested parties: ' . mb_strimwidth((string)$s['details'], 0, 300, '…'),
            'blight' => $n . ' open ticket(s), ' . money($s['amount']) . ' unpaid',
            'fire' => $n . ' building fire incident(s), latest ' . ($s['event_date'] ?? '?'),
            'rental' => 'Registered ' . ($s['event_date'] ?? ''),
            'rental_cert' => 'Active, expires ' . ($s['event_date'] ?? '?'),
            'sheriff_sale' => 'Sold at sheriff sale for ' . money($s['amount']) . ' (' . ($s['details'] ?? '') . '). Redemption about 6 months from sale.',
            'inherited' => ($s['details'] ?? '') . ($s['amount'] !== null ? ' for ' . money($s['amount']) : ''),
            default => (string)($s['details'] ?? ''),
        };
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /**
     * Keys counted by "stacked": distinct distress / life-event situations.
     * Tax counts once ("tax"), not again as pending or multi-year.
     */
    public static function stackable(): array
    {
        $keys = array_keys(array_filter(self::all(), static fn($m) => in_array($m['group'], ['distress', 'life'], true)));
        return array_values(array_diff($keys, ['tax_pending', 'tax_multi', 'tax_big']));
    }

    /** @return array{0:string,1:array} SQL condition + params for one motivator */
    public static function sql(string $key): array
    {
        $m = self::all()[$key] ?? throw new \InvalidArgumentException("Unknown motivator $key");
        return [$m['sql'], $m['params'] ?? []];
    }

    /** @return array{0:string,1:array} "at least $n stackable motivators" */
    public static function stackSql(int $n): array
    {
        $parts = [];
        $params = [];
        foreach (self::stackable() as $k) {
            [$s, $p] = self::sql($k);
            $parts[] = "($s)";
            array_push($params, ...$p);
        }
        return ['(' . implode(' + ', $parts) . ') >= ?', [...$params, $n]];
    }

    /**
     * Which motivators a property has, from its row and active signals (rows with type, source_ref, event_date).
     *
     * @return array<string> motivator keys
     */
    public static function evaluate(array $p, array $signals, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $by = [];
        foreach ($signals as $s) {
            $by[$s['type']][] = $s;
        }
        $has = static fn(string $t): bool => !empty($by[$t]);
        $out = [];
        if ($has('tax_forfeiture')) {
            $out[] = 'tax';
            foreach ($by['tax_forfeiture'] as $s) {
                if (($s['event_date'] ?? '') >= $today) {
                    $out[] = 'tax_pending';
                    break;
                }
            }
            if (count(array_unique(array_column($by['tax_forfeiture'], 'source_ref'))) >= 2) {
                $out[] = 'tax_multi';
            }
            foreach ($by['tax_forfeiture'] as $s) {
                if (($s['amount'] ?? null) !== null && (float)$s['amount'] >= self::BIG_TAX) {
                    $out[] = 'tax_big';
                    break;
                }
            }
        }
        $redemptionFrom = gmdate('Y-m-d', strtotime($today . ' UTC') - self::REDEMPTION_DAYS * 86400);
        foreach ($by['sheriff_sale'] ?? [] as $s) {
            if (($s['event_date'] ?? '') >= $redemptionFrom) {
                $out[] = 'sheriff_sale';
                break;
            }
        }
        foreach (['blight', 'fire', 'vacant', 'demolition', 'inherited'] as $t) {
            if ($has($t)) {
                $out[] = $t;
            }
        }
        if ($has('rental')) {
            $out[] = 'landlord';
            if (!$has('rental_cert')) {
                $out[] = 'rental_noncompliant';
            }
        }
        $re = '/' . self::ESTATE_RE . '/';
        if (preg_match($re, (string)($p['owner1'] ?? '')) || preg_match($re, (string)($p['owner2'] ?? ''))) {
            $out[] = 'estate_owner';
        }
        if ((int)($p['is_absentee'] ?? 0) === 1 || (($p['dm_owner_occupied'] ?? null) !== null && (int)$p['dm_owner_occupied'] === 0)) {
            $out[] = 'absentee';
        }
        if ((int)($p['is_out_of_state'] ?? 0) === 1) {
            $out[] = 'out_of_state';
        }
        if (!empty($p['sale_date']) && $p['sale_date'] <= date('Y-m-d', strtotime($today . ' -20 years'))) {
            $out[] = 'long_owned';
        }
        $out[] = (int)($p['is_entity'] ?? 0) === 1 ? 'entity' : 'individual';
        if (($p['dm_equity_pct'] ?? null) !== null && (float)$p['dm_equity_pct'] >= 50) {
            $out[] = 'high_equity';
        }
        if ((int)($p['dm_matched'] ?? 0) === 1 && ($p['dm_loan_balance'] ?? null) !== null && (int)$p['dm_loan_balance'] === 0) {
            $out[] = 'free_clear';
        }
        // registry order
        return array_values(array_intersect(array_keys(self::all()), $out));
    }

    /** Number of distinct stackable situations (tax counts once). */
    public static function stackCount(array $keys): int
    {
        return count(array_intersect($keys, self::stackable()));
    }
}
