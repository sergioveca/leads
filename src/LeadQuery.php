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
        $valid = Motivators::all();
        $keys = static fn($v): array => array_values(array_unique(array_filter(array_map('strval', (array)($v ?? [])), static fn($k) => isset($valid[$k]))));
        // the picker on the Leads page sends m[key]=all|any|none
        foreach ((array)($in['m'] ?? []) as $k => $mode) {
            if (isset($valid[$k]) && in_array($mode, ['all', 'any', 'none'], true)) {
                $in[$mode] = array_merge((array)($in[$mode] ?? []), [$k]);
            }
        }
        $all = $keys($in['all'] ?? []);
        // old single filters (bookmarks, saved links) map onto motivators
        $all = array_merge($all, match ($in['signal'] ?? '') {
            'tax_forfeiture' => ['tax'], 'blight' => ['blight'], 'both' => ['tax', 'blight'], default => [],
        });
        if (!empty($in['absentee'])) {
            $all[] = 'absentee';
        }
        if (in_array($in['owner'] ?? '', ['person', 'entity'], true)) {
            $all[] = $in['owner'] === 'person' ? 'individual' : 'entity';
        }
        $any = $keys($in['any'] ?? []);
        $none = $keys($in['none'] ?? []);
        $stack = max(0, min(6, (int)($in['stack'] ?? 0)));
        return [
            'q' => trim((string)($in['q'] ?? '')),
            'min' => ($in['min'] ?? '') === '' ? self::defaultMin($view, (bool)($all || $any || $none || $stack > 0)) : max(0, min(100, (int)$in['min'])),
            'all' => array_values(array_unique($all)),
            'any' => $any,
            'none' => $none,
            'stack' => $stack,
            'ptype' => in_array($in['ptype'] ?? '', ['house', 'land'], true) ? $in['ptype'] : '',
            'muni' => trim((string)($in['muni'] ?? '')),
            'traced' => in_array($in['traced'] ?? '', ['yes', 'no', 'phone'], true) ? $in['traced'] : '',
            'zip' => preg_replace('/\D/', '', (string)($in['zip'] ?? '')) ?? '',
            'status' => ($status === 'any' || $status === 'open' || isset(Pipeline::STATUSES[$status])) ? $status : 'open',
            'view' => $view,
            'client' => max(0, (int)($in['client'] ?? 0)),
            'sort' => in_array($in['sort'] ?? '', ['score', 'equity', 'recent', 'address', 'delivered', 'status'], true) ? $in['sort'] : 'score',
        ];
    }

    /**
     * Score floor when none is given: 50 for the plain lead list; 0 when motivators pick the leads (they decide,
     * not the score) and for delivered leads (listed for outcome tracking).
     */
    public static function defaultMin(string $view, bool $hasMotivators): int
    {
        return ($view === 'delivered' || $hasMotivators) ? 0 : 50;
    }

    /**
     * Parcel ids for a bulk action: the ticked ones, or every lead matching the saved filter query when the
     * user chose "select all matching". Leads exclusive to another client are never included.
     *
     * @return string[]
     */
    public static function bulkIds(array $post, array $user, int $limit, string $extraWhere = ''): array
    {
        if (($post['scope'] ?? '') === 'all') {
            parse_str((string)($post['query'] ?? ''), $in);
            $f = self::filters($in);
            [$where, $params] = self::where($f, $user);
            $own = $user['role'] === 'admin' ? '' : ' AND (p.exclusive_client_id IS NULL OR p.exclusive_client_id = ' . (int)$user['client_id'] . ')';
            return array_column(Db::all(
                "SELECT p.parcel_id FROM properties p WHERE $where$own" . ($extraWhere !== '' ? " AND ($extraWhere)" : '') . ' ORDER BY ' . self::order($f['sort']) . ' LIMIT ' . $limit,
                $params
            ), 'parcel_id');
        }
        return array_slice(array_values(array_unique(array_map('strval', (array)($post['ids'] ?? [])))), 0, $limit);
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
        // motivators: must have every one of `all`, at least one of `any`, none of `none`
        foreach ($f['all'] as $k) {
            [$sql, $args] = Motivators::sql($k);
            $w[] = "($sql)";
            array_push($p, ...$args);
        }
        if ($f['any']) {
            $ors = [];
            foreach ($f['any'] as $k) {
                [$sql, $args] = Motivators::sql($k);
                $ors[] = "($sql)";
                array_push($p, ...$args);
            }
            $w[] = '(' . implode(' OR ', $ors) . ')';
        }
        foreach ($f['none'] as $k) {
            [$sql, $args] = Motivators::sql($k);
            $w[] = "NOT ($sql)";
            array_push($p, ...$args);
        }
        if ($f['stack'] > 0) {
            [$sql, $args] = Motivators::stackSql($f['stack']);
            $w[] = $sql;
            array_push($p, ...$args);
        }
        if ($f['ptype'] === 'house') {
            $w[] = "p.property_class LIKE '%RESIDENTIAL%' AND p.property_class NOT LIKE '%VACANT%'";
        } elseif ($f['ptype'] === 'land') {
            $w[] = "p.property_class LIKE '%VACANT%'";
        }
        if ($f['muni'] !== '') {
            $w[] = 'p.municipality = ?';
            $p[] = strtoupper($f['muni']);
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
