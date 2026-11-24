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

    public static function query(string $url, array $params): array
    {
        $params += ['f' => 'json', 'returnGeometry' => 'false'];
        $data = Http::postForm($url, $params, 90);
        if (isset($data['error'])) {
            throw new \RuntimeException('ArcGIS error: ' . json_encode($data['error']));
        }
        return $data;
    }

    public static function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}
