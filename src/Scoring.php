<?php
declare(strict_types=1);

namespace Leads;

/**
 * Lead score 0-100 = stacked distress signals + owner/property situation.
 * Reasons are stored so every score is explainable on screen.
 */
final class Scoring
{
    public static function rescore(int $limit = 5000): int
    {
        $rows = Db::all('SELECT * FROM properties WHERE needs_score = 1 LIMIT ' . (int)$limit);
        if (!$rows) {
            return 0;
        }
        $ids = array_column($rows, 'parcel_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sigs = [];
        foreach (Db::all("SELECT parcel_id, type, source_ref, amount, item_count, event_date FROM signals WHERE active = 1 AND parcel_id IN ($in)", $ids) as $s) {
            $sigs[$s['parcel_id']][] = $s;
        }
        $currentListYear = (int)(Db::setting('treasurer_latest_published') ?: date('Y'));
        foreach ($rows as $p) {
            [$score, $reasons] = self::score($p, $sigs[$p['parcel_id']] ?? [], $currentListYear);
            Db::run('UPDATE properties SET score = ?, score_reasons = ?, needs_score = 0 WHERE parcel_id = ?', [$score, mb_substr(implode('; ', $reasons), 0, 500), $p['parcel_id']]);
        }
        return count($rows);
    }

    /** @return array{0:int,1:array<string>} */
    public static function score(array $p, array $signals, int $currentListYear): array
    {
        if ((int)$p['is_excluded'] === 1) {
            return [0, ['Excluded: ' . ($p['exclude_reason'] ?? '')]];
        }
        $s = 0;
        $r = [];
        $tax = array_values(array_filter($signals, static fn($x) => $x['type'] === 'tax_forfeiture'));
        $blight = array_values(array_filter($signals, static fn($x) => $x['type'] === 'blight'));

        if ($tax) {
            $years = array_map(static fn($x) => (int)$x['source_ref'], $tax);
            $max = max($years);
            // owner changed after the foreclosure date => that tax episode is over
            $resolved = !empty($p['sale_date']) && $p['sale_date'] > sprintf('%d-03-31', $max);
            if ($resolved) {
                $r[] = "Sold after $max tax foreclosure date (+0)";
            } elseif (sprintf('%d-03-31', $max) >= date('Y-m-d')) {
                // foreclosure date still ahead: owner must pay or lose the property
                $s += 45;
                $r[] = "On $max tax foreclosure list, foreclosure pending (+45)";
            } else {
                $s += 25;
                $r[] = "On $max tax foreclosure list, still same owner (+25)";
            }
            if (count(array_unique($years)) > 1 && !$resolved) {
                $s += 10;
                $r[] = 'Forfeited in multiple years (+10)';
            }
        }
        if ($blight) {
            $b = $blight[0];
            $s += 15;
            $r[] = sprintf('Open blight tickets: %d, $%s due (+15)', (int)$b['item_count'], number_format((float)$b['amount']));
            if ((int)$b['item_count'] >= 3) {
                $s += 5;
                $r[] = '3+ blight tickets (+5)';
            }
            if ((float)$b['amount'] >= 1000) {
                $s += 5;
                $r[] = '$1,000+ blight balance (+5)';
            }
            if (!empty($b['event_date']) && $b['event_date'] >= date('Y-m-d', strtotime('-12 months'))) {
                $s += 5;
                $r[] = 'Ticket in last 12 months (+5)';
            }
        }
        if ($tax && $blight) {
            $s += 10;
            $r[] = 'Tax + blight stacked (+10)';
        }
        $m = Motivators::evaluate($p, $signals);
        $by = [];
        foreach ($signals as $x) {
            $by[$x['type']][] = $x;
        }
        $latest = static fn(string $t): string => (string)max(array_column($by[$t] ?? [], 'event_date') ?: ['']);
        if (in_array('sheriff_sale', $m, true)) {
            $until = date('M j, Y', strtotime($latest('sheriff_sale') . ' +6 months'));
            $s += 40;
            $r[] = "Mortgage foreclosure sheriff sale {$latest('sheriff_sale')}, redemption until about $until (+40)";
        }
        if (in_array('demolition', $m, true)) {
            $s += 25;
            $r[] = 'On the city demolition list (+25)';
        }
        if (in_array('fire', $m, true)) {
            $s += 20;
            $r[] = 'Building fire ' . $latest('fire') . ' (+20)';
            if ($latest('fire') >= date('Y-m-d', strtotime('-12 months'))) {
                $s += 5;
                $r[] = 'Fire in last 12 months (+5)';
            }
        }
        if (in_array('inherited', $m, true)) {
            $s += 20;
            $r[] = 'Inherited: estate transfer ' . $latest('inherited') . ' (+20)';
        }
        if (in_array('estate_owner', $m, true)) {
            $s += 15;
            $r[] = 'Owner of record is an estate / heirs (+15)';
        }
        if (in_array('vacant', $m, true)) {
            $s += 15;
            $r[] = 'Registered vacant (+15)';
        }
        if (in_array('rental_noncompliant', $m, true)) {
            $s += 10;
            $r[] = 'Rental without certificate of compliance (+10)';
        } elseif (in_array('landlord', $m, true)) {
            $s += 5;
            $r[] = 'Registered rental (landlord) (+5)';
        }
        if (in_array('tax_big', $m, true)) {
            $owed = max(array_map(static fn($x) => (float)($x['amount'] ?? 0), $by['tax_forfeiture'] ?? [['amount' => 0]]));
            $s += 5;
            $r[] = sprintf('Owes $%s in back taxes (+5)', number_format($owed));
        }
        if (in_array('long_owned', $m, true)) {
            $s += 5;
            $r[] = 'Owned 20+ years (+5)';
        }
        if (Motivators::stackCount($m) >= 3) {
            $s += 10;
            $r[] = Motivators::stackCount($m) . ' distress signals stacked (+10)';
        }
        if ((int)($p['is_absentee'] ?? 0) === 1) {
            $s += 10;
            $r[] = 'Absentee owner (+10)';
            if ((int)($p['is_out_of_state'] ?? 0) === 1) {
                $s += 5;
                $r[] = 'Out-of-state owner (+5)';
            }
        } elseif ($p['dm_owner_occupied'] !== null && (int)$p['dm_owner_occupied'] === 0) {
            $s += 8;
            $r[] = 'Not owner-occupied (DealMachine) (+8)';
        }
        if ((int)($p['is_entity'] ?? 0) === 1) {
            $s -= 10;
            $r[] = 'Company/trust owner, often an investor (-10)';
        }
        $class = strtoupper((string)($p['property_class'] ?? ''));
        if (str_contains($class, 'RESIDENTIAL-IMPROVED') || str_contains($class, 'RESIDENTIAL IMPROVED')) {
            $s += 5;
            $r[] = 'Residential with structure (+5)';
        } elseif (str_contains($class, 'VACANT')) {
            $s -= 10;
            $r[] = 'Vacant land (-10)';
        }
        if ($p['dm_equity_pct'] !== null && (float)$p['dm_equity_pct'] >= 50) {
            $s += 10;
            $r[] = sprintf('Equity %d%% (+10)', (int)$p['dm_equity_pct']);
        }
        if ($p['dm_loan_balance'] !== null && (int)$p['dm_loan_balance'] === 0 && $p['dm_matched']) {
            $s += 5;
            $r[] = 'Free and clear (+5)';
        }
        return [max(0, min(100, $s)), $r];
    }
}
