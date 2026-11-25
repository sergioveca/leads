<?php
declare(strict_types=1);

namespace Leads;

/** Saved lists: named Leads-page filter combinations with live counts. */
final class Lists
{
    /** Normalized query string for a filter set (only non-default values), used for saving and links. */
    public static function query(array $f): string
    {
        $q = [];
        foreach (['all', 'any', 'none'] as $k) {
            if ($f[$k]) {
                $q[$k] = $f[$k];
            }
        }
        $defaults = LeadQuery::filters(['view' => $f['view']]);
        foreach (['q', 'min', 'stack', 'ptype', 'muni', 'traced', 'zip', 'status', 'view', 'client', 'sort'] as $k) {
            if ($f[$k] !== $defaults[$k] && $f[$k] !== '' && $f[$k] !== 0) {
                $q[$k] = $f[$k];
            }
        }
        if ($f['min'] !== $defaults['min']) {
            $q['min'] = $f['min'];
        }
        return http_build_query($q);
    }

    public static function filters(array $list): array
    {
        parse_str((string)$list['query'], $in);
        return LeadQuery::filters($in);
    }

    public static function all(): array
    {
        return Db::all('SELECT l.*, u.email AS created_by_email FROM saved_lists l LEFT JOIN users u ON u.id = l.created_by ORDER BY l.sort_order, l.id');
    }

    /**
     * Recounts lists (admin view: delivered leads are excluded by the default pool view anyway).
     * Run hourly by the scheduler and on demand; the Lists page shows these cached numbers.
     * Returns the number of lists counted.
     */
    public static function recount(?int $id = null): int
    {
        $lists = $id ? Db::all('SELECT * FROM saved_lists WHERE id = ?', [$id]) : Db::all('SELECT * FROM saved_lists');
        foreach ($lists as $l) {
            [$count, $new] = self::count($l);
            Db::run('UPDATE saved_lists SET last_count = ?, last_new = ?, last_counted_at = UTC_TIMESTAMP() WHERE id = ?', [$count, $new, $l['id']]);
        }
        if ($id === null) {
            self::countMotivators();
        }
        return count($lists);
    }

    /** Working-pool leads (open, any score) per motivator, for the counts shown in the Leads page picker. */
    public static function countMotivators(): array
    {
        [$where, $params] = LeadQuery::where(LeadQuery::filters(['min' => 0]));
        $counts = [];
        foreach (array_keys(Motivators::all()) as $k) {
            [$sql, $args] = Motivators::sql($k);
            $counts[$k] = (int)Db::val("SELECT COUNT(*) FROM properties p WHERE $where AND ($sql)", [...$params, ...$args]);
        }
        Db::setSetting('motivator_counts', (string)json_encode(['at' => gmdate('Y-m-d H:i:s'), 'counts' => $counts]));
        return $counts;
    }

    /** @return array{at:?string, counts:array<string,int>} last computed motivator counts */
    public static function motivatorCounts(): array
    {
        $d = json_decode((string)Db::setting('motivator_counts', ''), true);
        return ['at' => $d['at'] ?? null, 'counts' => $d['counts'] ?? []];
    }

    /** @return array{0:int,1:int} total leads, leads first seen since the last export */
    public static function count(array $list, ?array $user = null): array
    {
        [$where, $params] = LeadQuery::where(self::filters($list), $user);
        $since = $list['last_exported_at'] ?? $list['created_at'];
        $row = Db::one("SELECT COUNT(*) n, SUM(p.first_seen_at > ?) nw FROM properties p WHERE $where", [$since, ...$params]);
        return [(int)$row['n'], (int)$row['nw']];
    }

    /** Plain-English summary of a filter set, e.g. "Must: Absentee owner · Any of: Tax, Blight". */
    public static function describe(array $f): string
    {
        $parts = [];
        $names = static fn(array $keys): string => implode(', ', array_map([Motivators::class, 'label'], $keys));
        if ($f['all']) {
            $parts[] = 'Must have: ' . $names($f['all']);
        }
        if ($f['any']) {
            $parts[] = 'Any of: ' . $names($f['any']);
        }
        if ($f['none']) {
            $parts[] = 'Exclude: ' . $names($f['none']);
        }
        if ($f['stack'] > 1) {
            $parts[] = $f['stack'] . '+ signals stacked';
        }
        if ($f['ptype'] !== '') {
            $parts[] = $f['ptype'] === 'house' ? 'Houses only' : 'Vacant land only';
        }
        if ($f['muni'] !== '') {
            $parts[] = $f['muni'];
        }
        if ($f['zip'] !== '') {
            $parts[] = 'ZIP ' . $f['zip'];
        }
        if ($f['min'] > 0) {
            $parts[] = 'Score ' . $f['min'] . '+';
        }
        return $parts ? implode(' · ', $parts) : 'All leads';
    }
}
