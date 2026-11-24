<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Db;

/**
 * Fills owner / mailing address / class / tax status from the Detroit assessor parcel file
 * for Detroit parcels that have signals. 150 parcels per request (POST), oldest-synced first.
 */
final class DetroitParcels
{
    public const JOB = 'detroit_parcels';
    private const FIELDS = 'parcel_id,address,zip_code,taxpayer_1,taxpayer_2,taxpayer_address,taxpayer_city,taxpayer_state,taxpayer_zip_code,'
        . 'property_class_description,tax_status_description,amt_assessed_value,amt_taxable_value,year_built,total_floor_area,sale_date,amt_sale_price';

    /** Returns number of parcels synced (0 when nothing is due). */
    public static function batch(int $size = 150): int
    {
        $due = Db::all(
            "SELECT parcel_id FROM properties WHERE municipality = 'DETROIT'
             AND (parcel_synced_at IS NULL OR parcel_synced_at < UTC_TIMESTAMP() - INTERVAL 14 DAY)
             ORDER BY parcel_synced_at IS NOT NULL, parcel_synced_at LIMIT " . (int)$size
        );
        if (!$due) {
            return 0;
        }
        $ids = array_column($due, 'parcel_id');
        $where = 'parcel_id IN (' . implode(',', array_map([ArcGis::class, 'quote'], $ids)) . ')';
        $data = ArcGis::query(ArcGis::PARCELS, ['where' => $where, 'outFields' => self::FIELDS, 'resultRecordCount' => 1000]);
        $found = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($data['features'] ?? [] as $f) {
            $a = $f['attributes'] ?? [];
            $pid = (string)($a['parcel_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $found[$pid] = true;
            $addr = self::clean($a['address'] ?? '');
            $mail = self::clean($a['taxpayer_address'] ?? '');
            $mailState = strtoupper(self::clean($a['taxpayer_state'] ?? ''));
            $owner1 = self::clean($a['taxpayer_1'] ?? '');
            $taxStatus = self::clean($a['tax_status_description'] ?? '');
            [$excluded, $reason] = self::exclusion($owner1, $taxStatus);
            Db::run(
                'UPDATE properties SET address = IF(? <> \'\', ?, address), zip = ?, owner1 = ?, owner2 = ?, mail_address = ?, mail_city = ?, mail_state = ?, mail_zip = ?,
                 property_class = ?, tax_status = ?, assessed_value = ?, taxable_value = ?, year_built = ?, building_sqft = ?, sale_date = ?, sale_price = ?,
                 is_absentee = ?, is_out_of_state = ?, is_entity = ?, is_excluded = ?, exclude_reason = ?, parcel_synced_at = ?, needs_score = 1, updated_at = ?
                 WHERE parcel_id = ?',
                [
                    $addr, $addr, self::clean($a['zip_code'] ?? '') ?: null, $owner1 ?: null, self::clean($a['taxpayer_2'] ?? '') ?: null,
                    $mail ?: null, self::clean($a['taxpayer_city'] ?? '') ?: null, $mailState ?: null, self::clean($a['taxpayer_zip_code'] ?? '') ?: null,
                    self::clean($a['property_class_description'] ?? '') ?: null, $taxStatus ?: null,
                    self::int($a['amt_assessed_value'] ?? null), self::int($a['amt_taxable_value'] ?? null),
                    self::int($a['year_built'] ?? null) ?: null, self::int($a['total_floor_area'] ?? null),
                    self::date($a['sale_date'] ?? null), self::int($a['amt_sale_price'] ?? null),
                    self::absentee($addr, $mail) ? 1 : 0, ($mailState !== '' && $mailState !== 'MI') ? 1 : 0,
                    self::isEntity($owner1) ? 1 : 0, $excluded ? 1 : 0, $reason, $now, $now, $pid,
                ]
            );
        }
        // parcels not in the current file (merged/split/retired): mark synced + excluded
        foreach ($ids as $pid) {
            if (!isset($found[$pid])) {
                Db::run("UPDATE properties SET parcel_synced_at = ?, is_excluded = 1, exclude_reason = 'Not in current Detroit parcel file', needs_score = 1 WHERE parcel_id = ?", [$now, $pid]);
            }
        }
        return count($ids);
    }

    /** Government, Land Bank and tax-exempt parcels are not acquisition leads. */
    public static function exclusion(string $owner, string $taxStatus): array
    {
        if ($taxStatus !== '' && strtoupper($taxStatus) !== 'TAXABLE') {
            return [true, 'Not taxable: ' . mb_substr($taxStatus, 0, 80)];
        }
        if (preg_match('/\b(DETROIT LAND BANK|LAND BANK AUTHORITY|CITY OF DETROIT|WAYNE COUNTY TREASURER|COUNTY OF WAYNE|STATE OF MICHIGAN|HOUSING COMMISSION|UNITED STATES|BOARD OF EDUCATION|DETROIT PUBLIC SCHOOLS)\b/i', $owner)) {
            return [true, 'Public owner'];
        }
        return [false, null];
    }

    public static function isEntity(string $owner): bool
    {
        return (bool)preg_match('/\b(LLC|L\.L\.C|INC|CORP|CORPORATION|COMPANY|CO\.|LP|LLP|LTD|TRUST|BANK|ASSOCIATION|ASSN|HOLDINGS|PROPERTIES|INVESTMENTS?|GROUP|PARTNERS|FUND|CHURCH|MINISTR)/i', $owner);
    }

    public static function absentee(string $propAddr, string $mailAddr): bool
    {
        if ($mailAddr === '' || $propAddr === '') {
            return false;
        }
        $norm = static fn(string $s): string => preg_replace('/[^A-Z0-9]/', '', strtoupper(preg_replace('/\b(STREET|ST|AVENUE|AVE|ROAD|RD|DRIVE|DR|BOULEVARD|BLVD|COURT|CT|LANE|LN|PLACE|PL)\b\.?/i', '', $s) ?? $s)) ?? '';
        return $norm($propAddr) !== $norm($mailAddr);
    }

    private static function clean(mixed $v): string
    {
        return trim(preg_replace('/\s+/', ' ', (string)$v) ?? '');
    }

    private static function int(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int)round((float)$v);
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
