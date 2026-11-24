<?php
declare(strict_types=1);

namespace Leads;

/** Small cURL wrapper used for ArcGIS, the Treasurer site and DealMachine. */
final class Http
{
    /** @return array{status:int, body:string, headers:array<string,string>} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 45): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Leads/' . LEADS_VERSION . ' (+https://leads.4-return.com)',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $h) use (&$respHeaders): int {
                $p = strpos($h, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
                }
                return strlen($h);
            },
        ]);
        $ca = Env::get('CURL_CA_BUNDLE');
        if ($ca) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP $method $url failed: $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$out, 'headers' => $respHeaders];
    }

    public static function getJson(string $url, array $query = [], int $timeout = 60): array
    {
        $full = $url . ($query ? (str_contains($url, '?') ? '&' : '?') . http_build_query($query) : '');
        $r = self::request('GET', $full, ['Accept: application/json'], null, $timeout);
        return self::decode($r, $full);
    }

    public static function postForm(string $url, array $fields, int $timeout = 60): array
    {
        $r = self::request('POST', $url, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query($fields), $timeout);
        return self::decode($r, $url);
    }

    private static function decode(array $r, string $url): array
    {
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException("Bad JSON from $url (HTTP {$r['status']})");
        }
        if ($r['status'] >= 400) {
            throw new \RuntimeException("HTTP {$r['status']} from $url: " . substr($r['body'], 0, 300));
        }
        return $data;
    }
}
