<?php
declare(strict_types=1);

namespace Leads\Sources;

use Leads\Http;

/** City of Detroit open data (ArcGIS FeatureServer) helpers. */
final class ArcGis
{
    public const BASE = 'https://services2.arcgis.com/qvkbeam7Wirps6zC/ArcGIS/rest/services';
    public const PARCELS = self::BASE . '/parcel_file_current/FeatureServer/0/query';
    public const BLIGHT = self::BASE . '/blight_tickets/FeatureServer/0/query';

    /**
     * ArcGIS Online sometimes answers a slow (cold-cache) query with HTTP 400 "Invalid query parameters" or times out,
     * and the same query succeeds in ~1 s moments later (seen on assessor_property_sales_view). Retry twice.
     */
    public static function query(string $url, array $params, int $attempts = 3): array
    {
        $params += ['f' => 'json', 'returnGeometry' => 'false'];
        for ($try = 1; ; $try++) {
            try {
                $data = Http::postForm($url, $params, 90);
                if (isset($data['error'])) {
                    throw new \RuntimeException('ArcGIS error: ' . json_encode($data['error']));
                }
                return $data;
            } catch (\RuntimeException $e) {
                $transient = preg_match('/Invalid query parameters|Unable to perform query|timed out|HTTP 5\d\d|failed:/i', $e->getMessage());
                if (!$transient || $try >= $attempts) {
                    throw $e;
                }
                sleep(3 * $try);
            }
        }
    }

    public static function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}
