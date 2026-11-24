<?php
declare(strict_types=1);

namespace Leads;

/** Builds the filtered lead list used by the Leads page and CSV export. */
final class LeadQuery
{
    public static function filters(array $in): array
    {
        $view = in_array($in['view'] ?? '', ['pool', 'delivered', 'all'], true) ? $in['view'] : 'pool';
        $status = (string)($in['status'] ?? 'open');
        return [
            'q' => trim((string)($in['q'] ?? '')),
            // delivered leads are listed for outcome tracking, so no score floor by default there
            'min' => ($in['min'] ?? '') === '' ? ($view === 'delivered' ? 0 : 50) : max(0, min(100, (int)$in['min'])),
            'signal' => in_array($in['signal'] ?? '', ['tax_forfeiture', 'blight', 'both'], true) ? $in['signal'] : '',
            'muni' => trim((string)($in['muni'] ?? '')),
            'absentee' => !empty($in['absentee']),
            'traced' => in_array($in['traced'] ?? '', ['yes', 'no', 'phone'], true) ? $in['traced'] : '',
            'zip' => preg_replace('/\D/', '', (string)($in['zip'] ?? '')) ?? '',
            'owner' => in_array($in['owner'] ?? '', ['person', 'entity'], true) ? $in['owner'] : '',
            'status' => ($status === 'any' || $status === 'open' || isset(Pipeline::STATUSES[$status])) ? $status : 'open',
            'view' => $view,
            'client' => max(0, (int)($in['client'] ?? 0)),
            'sort' => in_array($in['sort'] ?? '', ['score', 'equity', 'recent', 'address', 'delivered', 'status'], true) ? $in['sort'] : 'score',
        ];
    }

    /**
     * @param array|null $user signed-in user; client users never see other clients' exclusive leads
     * @return array{0:string,1:array} WHERE clause and params
     */
    public static function where(array $f, ?array $user = null): array
    {
        $w = ['p.is_excluded = 0', 'p.score >= ?'];
        $p = [$f['min']];
        // pool = not delivered to anyone (4Return's working list); delivered = exclusive to a client
        if ($f['view'] === 'pool') {
            $w[] = 'p.exclusive_client_id IS NULL';
        } elseif ($f['view'] === 'delivered') {
            $w[] = 'p.exclusive_client_id IS NOT NULL';
        }
        if ($f['client'] > 0 && $f['view'] !== 'pool') {
            $w[] = 'p.exclusive_client_id = ?';
            $p[] = $f['client'];
        }
        if ($user !== null && $user['role'] !== 'admin') {
            $w[] = '(p.exclusive_client_id IS NULL OR p.exclusive_client_id = ?)';
            $p[] = (int)$user['client_id'];
        }
        if ($f['status'] === 'open') {
            $w[] = "p.status NOT IN ('dead', 'closed')";
        } elseif ($f['status'] !== 'any') {
            $w[] = 'p.status = ?';
            $p[] = $f['status'];
        }
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
        if ($f['owner'] === 'person') {
            $w[] = 'COALESCE(p.is_entity, 0) = 0';
        } elseif ($f['owner'] === 'entity') {
            $w[] = 'p.is_entity = 1';
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
            'delivered' => 'p.delivered_at IS NULL, p.delivered_at DESC, p.score DESC',
            'status' => 'p.status_at IS NULL, p.status_at DESC, p.score DESC',
            default => 'p.score DESC, p.parcel_id',
        };
    }
}
