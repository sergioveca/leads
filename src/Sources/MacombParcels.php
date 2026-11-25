<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Db;

/**
 * Owner / address / value from Macomb County's public parcel layer for Macomb leads (keyed by BSA_ID, which is
 * the parcel number used in the Treasurer's foreclosure notice). The layer has no owner mailing address, so
 * absentee status stays unknown until DealMachine says "not owner-occupied".
 * Verified 2026-11-24: 332,993 parcels, fields below.
 */
final class MacombParcels
{
    public const JOB = 'macomb_parcels';
    public const URL = 'https://services6.arcgis.com/K0qS4r8AEJxrE8em/arcgis/rest/services/Macomb_County_Parcel_Data/FeatureServer/10/query';
    private const FIELDS = 'BSA_ID,TAX_ID,ADDRESS,CVT_NAME,propertyCityStateZip,ownername1,zoning,mborsev_0,mbortax';

    /** Returns number of parcels synced (0 when nothing is due). */
    public static function batch(int $size = 150): int
    {
        $ids = array_column(Db::all(
            "SELECT parcel_id FROM properties WHERE county = 'MACOMB'
             AND (parcel_synced_at IS NULL OR parcel_synced_at < UTC_TIMESTAMP() - INTERVAL 14 DAY)
             ORDER BY parcel_synced_at IS NOT NULL, parcel_synced_at LIMIT " . (int)$size
        ), 'parcel_id');
        if (!$ids) {
            return 0;
        }
        $where = 'BSA_ID IN (' . implode(',', array_map([ArcGis::class, 'quote'], $ids)) . ')';
        $data = ArcGis::query(self::URL, ['where' => $where, 'outFields' => self::FIELDS, 'resultRecordCount' => 1000]);
        $now = gmdate('Y-m-d H:i:s');
        $found = [];
        foreach ($data['features'] ?? [] as $f) {
            $a = $f['attributes'] ?? [];
            $pid = trim((string)($a['BSA_ID'] ?? ''));
            if ($pid === '' || isset($found[$pid])) {
                continue;
            }
            $found[$pid] = true;
            $owner = self::clean($a['ownername1'] ?? '');
            $addr = self::clean($a['ADDRESS'] ?? '');
            [$excluded, $reason] = self::exclusion($owner);
            $zip = preg_match('/\b(\d{5})\b/', (string)($a['propertyCityStateZip'] ?? ''), $m) ? $m[1] : null;
            Db::run(
                'UPDATE properties SET address = IF(? <> \'\', ?, address), zip = COALESCE(?, zip), owner1 = ?, municipality = ?, apn = ?,
                 property_class = ?, assessed_value = ?, taxable_value = ?, is_entity = ?, is_excluded = ?, exclude_reason = ?,
                 parcel_synced_at = ?, needs_score = 1, updated_at = ? WHERE parcel_id = ?',
                [
                    $addr, $addr, $zip, $owner ?: null, self::community((string)($a['CVT_NAME'] ?? '')), self::clean($a['TAX_ID'] ?? '') ?: null,
                    self::propertyClass((string)($a['zoning'] ?? '')), self::int($a['mborsev_0'] ?? null), self::int($a['mbortax'] ?? null),
                    DetroitParcels::isEntity($owner) ? 1 : 0, $excluded ? 1 : 0, $reason, $now, $now, $pid,
                ]
            );
        }
        foreach ($ids as $pid) {
            if (!isset($found[$pid])) {
                // not in the current parcel layer (split/combined): keep the lead, just stop retrying for 14 days
                Db::run('UPDATE properties SET parcel_synced_at = ? WHERE parcel_id = ?', [$now, $pid]);
            }
        }
        return count($ids);
    }

    /** Public owners are not acquisition leads. */
    public static function exclusion(string $owner): array
    {
        if (preg_match('/\b(CITY OF|TOWNSHIP OF|VILLAGE OF|COUNTY OF|MACOMB COUNTY|STATE OF MICHIGAN|LAND BANK|SCHOOL DISTRICT|PUBLIC SCHOOLS|BOARD OF EDUCATION|UNITED STATES|HOUSING COMMISSION)\b/i', $owner)) {
            return [true, 'Public owner'];
        }
        return [false, null];
    }

    /** Zoning code -> a class the rest of the app understands ("RESIDENTIAL…" / "COMMERCIAL…"). */
    public static function propertyClass(string $zoning): ?string
    {
        $z = strtoupper(trim($zoning));
        if ($z === '') {
            return null;
        }
        if (preg_match('/(^|[^A-Z])R[A-Z0-9]*|RES|^MH|^RM|^RT/', $z)) {
            return "RESIDENTIAL (zoning $z)";
        }
        if (preg_match('/^(C|B|O|M|I|P|LI|GI)/', $z)) {
            return "COMMERCIAL/OTHER (zoning $z)";
        }
        return "ZONING $z";
    }

    /** "SAINT CLAIR SHORES" and "ST CLAIR SHORES" are one community. */
    public static function community(string $cvt): string
    {
        $c = strtoupper(self::clean($cvt));
        return preg_replace('/^SAINT /', 'ST ', $c) ?: 'MACOMB COUNTY';
    }

    private static function clean(mixed $v): string
    {
        return trim(preg_replace('/\s+/', ' ', (string)$v) ?? '');
    }

    private static function int(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int)round((float)$v);
    }
}
