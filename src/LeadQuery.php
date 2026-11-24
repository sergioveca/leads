<?php
declare(strict_types=1);

namespace Leads;

/** Builds the filtered lead list used by the Leads page and CSV export. */
final class LeadQuery
{
    public static function filters(array $in): array
    {
        return [
            'q' => trim((string)($in['q'] ?? '')),
            'min' => ($in['min'] ?? '') === '' ? 50 : max(0, min(100, (int)$in['min'])),
            'signal' => in_array($in['signal'] ?? '', ['tax_forfeiture', 'blight', 'both'], true) ? $in['signal'] : '',
            'muni' => trim((string)($in['muni'] ?? '')),
            'absentee' => !empty($in['absentee']),
            'traced' => in_array($in['traced'] ?? '', ['yes', 'no', 'phone'], true) ? $in['traced'] : '',
            'zip' => preg_replace('/\D/', '', (string)($in['zip'] ?? '')) ?? '',
            'sort' => in_array($in['sort'] ?? '', ['score', 'equity', 'recent', 'address'], true) ? $in['sort'] : 'score',
        ];
    }

    /** @return array{0:string,1:array} WHERE clause and params */
    public static function where(array $f): array
    {
        $w = ['p.is_excluded = 0', 'p.score >= ?'];
        $p = [$f['min']];
        if ($f['q'] !== '') {
            $w[] = '(p.address LIKE ? OR p.owner1 LIKE ? OR p.parcel_id LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($p, $like, $like, $like);
        }
        if ($f['signal'] === 'both') {
            $w[] = "EXISTS (SELECT 1 FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = 'tax_forfeiture')";
            $w[] = "EXISTS (SELECT 1 FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = 'blight')";
        } elseif ($f['signal'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1 AND s.type = ?)';
            $p[] = $f['signal'];
        }
        if ($f['muni'] !== '') {
            $w[] = 'p.municipality = ?';
            $p[] = strtoupper($f['muni']);
        }
        if ($f['absentee']) {
            $w[] = '(p.is_absentee = 1 OR p.dm_owner_occupied = 0)';
        }
        if ($f['zip'] !== '') {
            $w[] = 'p.zip LIKE ?';
            $p[] = $f['zip'] . '%';
        }
        if ($f['traced'] === 'yes') {
            $w[] = 'p.dm_matched = 1';
        } elseif ($f['traced'] === 'no') {
            $w[] = 'p.traced_at IS NULL';
        } elseif ($f['traced'] === 'phone') {
            $w[] = 'EXISTS (SELECT 1 FROM contacts c JOIN phones ph ON ph.contact_id = c.id LEFT JOIN opt_outs o ON o.value = ph.number
                    WHERE c.parcel_id = p.parcel_id AND COALESCE(ph.is_dnc, 0) = 0 AND o.id IS NULL)';
        }
        return [implode(' AND ', $w), $p];
    }

    public static function order(string $sort): string
    {
        return match ($sort) {
            'equity' => 'p.dm_equity_pct IS NULL, p.dm_equity_pct DESC, p.score DESC',
            'recent' => 'p.first_seen_at DESC, p.score DESC',
            'address' => 'p.address',
            default => 'p.score DESC, p.parcel_id',
        };
    }
}
