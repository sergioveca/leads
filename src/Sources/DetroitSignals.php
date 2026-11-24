<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Db;

/**
 * City of Detroit open-data signals beyond blight, one config entry per source (all linked by parcel_id).
 * Each source runs a full paged pass daily (resumable, see Jobs::pagedPass); rows not seen in a finished
 * pass are deactivated, so signals that age out of a window or get resolved disappear on their own.
 *
 * "creates" = whether a parcel with only this signal becomes a property row (a lead). Modifiers such as
 * "registered rental" are stored for every parcel but only matter when the parcel has real distress.
 */
final class DetroitSignals
{
    public const PAGE_SIZE = 1000;

    /** @return array<string, array> job key => config */
    public static function sources(): array
    {
        $today = date('Y-m-d');
        $y3 = date('Y-m-d', strtotime('-3 years'));
        $y2 = date('Y-m-d', strtotime('-2 years'));
        return [
            'detroit_fire' => [
                'service' => 'Fire_Incidents', 'type' => 'fire', 'source' => 'Detroit Fire Dept incidents', 'ref' => 'building_fire', 'creates' => true,
                'where' => "incident_type_description = 'Building fire' AND parcel_id IS NOT NULL AND called_at >= DATE '$y3'",
                'stats' => ['n' => ['count', 'ObjectId'], 'last' => ['max', 'called_at']],
            ],
            'detroit_vacant' => [
                'service' => 'bseed_vacant_property_registrations', 'type' => 'vacant', 'source' => 'Detroit vacant property registrations', 'ref' => 'registered', 'creates' => true,
                'where' => 'parcel_id IS NOT NULL',
                'stats' => ['n' => ['count', 'ObjectId'], 'last' => ['max', 'issued_date']],
            ],
            'detroit_demo_pipeline' => [
                'service' => 'city_demolition_pipeline', 'type' => 'demolition', 'source' => 'Detroit demolition pipeline', 'ref' => 'pipeline', 'creates' => true,
                'where' => 'parcel_id IS NOT NULL',
                'stats' => ['n' => ['count', 'ObjectId']],
            ],
            'detroit_demo_contracted' => [
                'service' => 'city_contracted_demolitions', 'type' => 'demolition', 'source' => 'Detroit contracted demolitions', 'ref' => 'contracted', 'creates' => true,
                'where' => 'parcel_id IS NOT NULL',
                'stats' => ['n' => ['count', 'ObjectId'], 'last' => ['max', 'planned_demolition_date']],
            ],
            'detroit_rental' => [
                'service' => 'bseed_rental_registrations', 'type' => 'rental', 'source' => 'Detroit rental registrations', 'ref' => 'registered', 'creates' => false,
                'where' => 'parcel_id IS NOT NULL',
                'stats' => ['n' => ['count', 'ObjectId'], 'last' => ['max', 'issued_date']],
            ],
            'detroit_rental_cert' => [
                'service' => 'bseed_active_residential_compliance_certificates', 'type' => 'rental_cert', 'source' => 'Detroit certificates of compliance', 'ref' => 'active', 'creates' => false,
                'where' => 'parcel_id IS NOT NULL',
                'stats' => ['n' => ['count', 'ObjectId'], 'last' => ['max', 'expired_date']],
            ],
            // Sheriff's deeds after a mortgage foreclosure sale ("10-FORECLOSURE" from the sheriff; the same code is
            // also used for Treasurer/City sales, which are excluded). Michigan gives the owner a redemption period.
            'detroit_sheriff_sales' => [
                'service' => 'assessor_property_sales_view', 'type' => 'sheriff_sale', 'source' => 'Detroit assessor sales (sheriff deeds)', 'creates' => true,
                'where' => "term_of_sale = '10-FORECLOSURE' AND grantor LIKE '%SHERIFF%' AND parcel_id IS NOT NULL AND sale_date >= '$y2' AND sale_date <= '$today'",
                'rows' => 'parcel_id,sale_id,sale_date,amt_sale_price,grantor,grantee',
            ],
            // Transfers out of / into an estate or by death certificate: recently inherited homes.
            'detroit_inherited' => [
                'service' => 'assessor_property_sales_view', 'type' => 'inherited', 'source' => 'Detroit assessor sales (estate transfers)', 'creates' => true,
                'where' => "(term_of_sale IN ('07-DEATH CERTIFICATE', '08-ESTATE') OR grantor LIKE '%ESTATE%') AND parcel_id IS NOT NULL"
                    . " AND sale_date >= '$y3' AND sale_date <= '$today'",
                'rows' => 'parcel_id,sale_id,sale_date,amt_sale_price,grantor,grantee',
            ],
        ];
    }

    /** Imports one page of a source. Returns [rowsImported, finishedFullPass]. */
    public static function page(string $key, int $offset): array
    {
        $c = self::sources()[$key] ?? throw new \InvalidArgumentException("Unknown source $key");
        $url = ArcGis::BASE . '/' . $c['service'] . '/FeatureServer/0/query';
        $params = ['where' => $c['where'], 'resultOffset' => $offset, 'resultRecordCount' => self::PAGE_SIZE];
        if (isset($c['stats'])) {
            $stats = [];
            foreach ($c['stats'] as $out => [$fn, $field]) {
                $stats[] = ['statisticType' => $fn, 'onStatisticField' => $field, 'outStatisticFieldName' => $out];
            }
            $params += ['outStatistics' => json_encode($stats), 'groupByFieldsForStatistics' => 'parcel_id', 'orderByFields' => 'parcel_id'];
        } else {
            $params += ['outFields' => $c['rows'], 'orderByFields' => 'sale_id'];
        }
        $data = ArcGis::query($url, $params);
        $features = $data['features'] ?? [];
        $now = gmdate('Y-m-d H:i:s');
        $signals = [];
        foreach ($features as $f) {
            $a = $f['attributes'] ?? [];
            $pid = trim((string)($a['parcel_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $signals[] = isset($c['stats']) ? [
                'parcel_id' => $pid, 'type' => $c['type'], 'source' => $c['source'], 'source_ref' => $c['ref'],
                'amount' => null, 'item_count' => (int)($a['n'] ?? 1), 'event_date' => self::date($a['last'] ?? null), 'details' => null,
                'active' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ] : [
                'parcel_id' => $pid, 'type' => $c['type'], 'source' => $c['source'], 'source_ref' => 'sale:' . (int)($a['sale_id'] ?? 0),
                'amount' => isset($a['amt_sale_price']) ? round((float)$a['amt_sale_price'], 2) : null, 'item_count' => null,
                'event_date' => self::date($a['sale_date'] ?? null),
                'details' => mb_substr(trim((string)($a['grantor'] ?? '')) . ' → ' . trim((string)($a['grantee'] ?? '')), 0, 2000),
                'active' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ];
        }
        foreach (array_chunk($signals, 500) as $chunk) {
            Db::upsert('signals', $chunk, ['amount', 'item_count', 'event_date', 'details', 'active', 'last_seen_at']);
            $ids = array_values(array_unique(array_column($chunk, 'parcel_id')));
            if ($c['creates']) {
                Db::upsert('properties', array_map(
                    static fn($pid) => ['parcel_id' => $pid, 'municipality' => 'DETROIT', 'first_seen_at' => $now, 'updated_at' => $now, 'needs_score' => 1],
                    $ids
                ), ['needs_score', 'updated_at']);
            } else {
                $in = implode(',', array_fill(0, count($ids), '?'));
                Db::run("UPDATE properties SET needs_score = 1 WHERE parcel_id IN ($in)", $ids);
            }
        }
        $more = !empty($data['exceededTransferLimit']) || count($features) === self::PAGE_SIZE;
        return [count($signals), !$more];
    }

    /** After a full pass: rows of this source not seen since the pass started are deactivated. */
    public static function finishPass(string $key, string $passStartedUtc): int
    {
        $c = self::sources()[$key];
        $stale = 'type = ? AND source = ? AND active = 1 AND last_seen_at < ?';
        $args = [$c['type'], $c['source'], $passStartedUtc];
        Db::run("UPDATE properties p JOIN signals s ON s.parcel_id = p.parcel_id SET p.needs_score = 1 WHERE s.$stale", $args);
        return Db::run("UPDATE signals SET active = 0 WHERE $stale", $args)->rowCount();
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
