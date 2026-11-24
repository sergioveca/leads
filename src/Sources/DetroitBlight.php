<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Db;

/**
 * Detroit blight violation tickets, aggregated per parcel: open tickets with an unpaid balance
 * issued in the last N years. One signal row per parcel (type=blight, source_ref='open').
 * Paged with resultOffset (1,000 parcels per page) and resumable across runs.
 */
final class DetroitBlight
{
    public const JOB = 'detroit_blight';

    public static function where(): string
    {
        $since = date('Y-m-d', strtotime('-3 years'));
        return "amt_balance_due > 0 AND parcel_id IS NOT NULL AND ticket_issued_date >= DATE '$since'";
    }

    /** Imports one page. Returns [rowsImported, finishedFullPass]. */
    public static function page(int $offset): array
    {
        $data = ArcGis::query(ArcGis::BLIGHT, [
            'where' => self::where(),
            'outStatistics' => json_encode([
                ['statisticType' => 'count', 'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'n'],
                ['statisticType' => 'sum', 'onStatisticField' => 'amt_balance_due', 'outStatisticFieldName' => 'bal'],
                ['statisticType' => 'max', 'onStatisticField' => 'ticket_issued_date', 'outStatisticFieldName' => 'last_issued'],
            ]),
            'groupByFieldsForStatistics' => 'parcel_id',
            'orderByFields' => 'parcel_id',
            'resultOffset' => $offset,
            'resultRecordCount' => 1000,
        ]);
        $features = $data['features'] ?? [];
        $now = gmdate('Y-m-d H:i:s');
        $signals = [];
        $props = [];
        foreach ($features as $f) {
            $a = $f['attributes'] ?? [];
            $pid = trim((string)($a['parcel_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $last = self::date($a['last_issued'] ?? null);
            $signals[] = [
                'parcel_id' => $pid,
                'type' => 'blight',
                'source' => 'Detroit blight tickets',
                'source_ref' => 'open',
                'amount' => round((float)($a['bal'] ?? 0), 2),
                'item_count' => (int)($a['n'] ?? 0),
                'event_date' => $last,
                'details' => null,
                'active' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ];
            $props[] = ['parcel_id' => $pid, 'municipality' => 'DETROIT', 'first_seen_at' => $now, 'updated_at' => $now, 'needs_score' => 1];
        }
        foreach (array_chunk($signals, 500) as $chunk) {
            Db::upsert('signals', $chunk, ['amount', 'item_count', 'event_date', 'active', 'last_seen_at']);
        }
        foreach (array_chunk($props, 500) as $chunk) {
            Db::upsert('properties', $chunk, ['needs_score', 'updated_at']);
        }
        $more = !empty($data['exceededTransferLimit']) || count($features) === 1000;
        return [count($signals), !$more];
    }

    /** After a full pass: tickets no longer open (paid/voided) are deactivated. */
    public static function finishPass(string $passStartedUtc): int
    {
        $n = Db::run(
            "UPDATE signals SET active = 0 WHERE type = 'blight' AND active = 1 AND last_seen_at < ?",
            [$passStartedUtc]
        )->rowCount();
        if ($n) {
            Db::run("UPDATE properties p JOIN signals s ON s.parcel_id = p.parcel_id SET p.needs_score = 1 WHERE s.type = 'blight' AND s.active = 0 AND s.last_seen_at < ?", [$passStartedUtc]);
        }
        return $n;
    }

    private static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return gmdate('Y-m-d', (int)($v / 1000));
        }
        $t = strtotime((string)$v);
        return $t ? date('Y-m-d', $t) : null;
    }
}
