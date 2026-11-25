<?php
declare(strict_types=1);

namespace Leads;

/** DealMachine REST API client (see docs/dealmachine.md). */
final class DealMachine
{
    public const WAYNE_FIPS = '26163';

    public function __construct(private string $apiKey)
    {
    }

    public static function base(): string
    {
        return rtrim((string)Env::get('DEALMACHINE_BASE_URL', 'https://api.v2.dealmachine.com/v1'), '/');
    }

    private function call(string $method, string $path, ?array $body = null, int $timeout = 120): array
    {
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json', 'X-DealMachine-Source: leads'];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        $r = Http::request($method, self::base() . $path, $headers, $payload, $timeout);
        $data = json_decode($r['body'], true);
        if ($r['status'] === 429) {
            throw new DealMachineException('DealMachine rate limit reached; will retry next run', 429);
        }
        if ($r['status'] === 402) {
            throw new DealMachineException('DealMachine credits exhausted (or prospect limit reached): ' . ($data['error']['message'] ?? ''), 402);
        }
        if ($r['status'] >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['error']['message'] ?? substr($r['body'], 0, 200)) : substr($r['body'], 0, 200);
            throw new DealMachineException("DealMachine HTTP {$r['status']} on $path: $msg", $r['status']);
        }
        return $data;
    }

    public function usage(): array
    {
        return $this->call('GET', '/usage');
    }

    /** County FIPS codes DealMachine expects for APN lookups. */
    public const FIPS = ['WAYNE' => self::WAYNE_FIPS, 'MACOMB' => '26099', 'OAKLAND' => '26125'];

    /** @param string[] $apns max 250, all in one county */
    public function enrichByApn(array $apns, string $audience = 'owners', string $fips = self::WAYNE_FIPS): array
    {
        return $this->call('POST', '/enrichment/apn', [
            'data' => array_map(static fn($a) => ['apn' => $a], array_values($apns)),
            'location' => ['type' => 'county', 'code' => $fips],
            'contact_audience' => $audience,
        ]);
    }

    /** @param string[] $addresses full addresses, max 250 */
    public function enrichByAddress(array $addresses, string $audience = 'owners'): array
    {
        return $this->call('POST', '/enrichment/address', [
            'data' => array_map(static fn($a) => ['full_address' => $a], array_values($addresses)),
            'contact_audience' => $audience,
        ]);
    }

    /** @param string[] $numbers max 1000 */
    public function checkDnc(array $numbers): array
    {
        return $this->call('POST', '/phones/dnc', ['phones' => array_map(static fn($n) => ['number' => $n], array_values($numbers))]);
    }

    /** @param string[] $propertyIds dm_property_id values, max 1000 */
    public function addProspects(array $propertyIds): array
    {
        return $this->call('POST', '/prospects', ['record_type' => 'property', 'record_ids' => array_values($propertyIds)]);
    }
}
