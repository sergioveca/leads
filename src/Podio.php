<?php
declare(strict_types=1);

namespace Leads;

/**
 * Podio API client for Home Detroit's CRM, signed in as a Podio user (password flow, PODIO_* in .env), so items show
 * "<user> via 4Return Leads". Fields are matched by their label (the external ids of Home Detroit's apps are not ours
 * to depend on), and values are converted by field type. Anything that cannot be filled is reported, never guessed.
 */
final class Podio
{
    public const API = 'https://api.podio.com';

    /** @var array<string,array> app definitions cached for this request */
    private static array $apps = [];

    public static function configured(): bool
    {
        foreach (['PODIO_CLIENT_ID', 'PODIO_CLIENT_SECRET', 'PODIO_USERNAME', 'PODIO_PASSWORD'] as $k) {
            if ((string)Env::get($k, '') === '') {
                return false;
            }
        }
        return true;
    }

    private static function token(bool $fresh = false): string
    {
        $saved = json_decode((string)Crypto::decrypt(Db::setting('podio_token')), true);
        if (!$fresh && is_array($saved) && ($saved['expires'] ?? 0) > time() + 120 && !empty($saved['access'])) {
            return (string)$saved['access'];
        }
        $form = ['client_id' => Env::get('PODIO_CLIENT_ID'), 'client_secret' => Env::get('PODIO_CLIENT_SECRET')];
        $form += (!$fresh && is_array($saved) && !empty($saved['refresh']))
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $saved['refresh']]
            : ['grant_type' => 'password', 'username' => Env::get('PODIO_USERNAME'), 'password' => Env::get('PODIO_PASSWORD')];
        $r = Http::request('POST', self::API . '/oauth/token', ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query($form), 30);
        $d = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($d) || empty($d['access_token'])) {
            if (($form['grant_type'] ?? '') === 'refresh_token') {
                return self::token(true); // refresh token expired: sign in again
            }
            $why = is_array($d) ? (string)($d['error_description'] ?? $d['error'] ?? '') : '';
            throw new \RuntimeException('Podio sign-in failed (HTTP ' . $r['status'] . ')' . ($why !== '' ? ": $why" : '') . '. Check PODIO_* in .env.');
        }
        Db::setSetting('podio_token', Crypto::encrypt((string)json_encode([
            'access' => $d['access_token'], 'refresh' => $d['refresh_token'] ?? null, 'expires' => time() + (int)($d['expires_in'] ?? 3600),
        ])));
        return (string)$d['access_token'];
    }

    public static function call(string $method, string $path, ?array $body = null, bool $retry = true): array
    {
        $r = Http::request($method, self::API . $path, [
            'Accept: application/json', 'Content-Type: application/json', 'Authorization: OAuth2 ' . self::token(),
        ], $body === null ? null : (string)json_encode($body), 40);
        if ($r['status'] === 401 && $retry) {
            Db::setSetting('podio_token', '');
            return self::call($method, $path, $body, false);
        }
        $d = json_decode($r['body'], true);
        if ($r['status'] >= 400) {
            $why = is_array($d) ? (string)($d['error_description'] ?? $d['error'] ?? '') : substr($r['body'], 0, 200);
            throw new \RuntimeException("Podio $method $path: HTTP {$r['status']} $why");
        }
        return is_array($d) ? $d : [];
    }

    /**
     * An app by its Podio URL path "org/space/app" (e.g. "detroitprogresscom/dpgr-crm/seller-leads") or numeric id.
     * Cached for 6 hours in settings so field lists are not fetched on every page.
     */
    public static function app(string $ref): array
    {
        if (isset(self::$apps[$ref])) {
            return self::$apps[$ref];
        }
        $key = 'podio_app_' . substr(sha1($ref), 0, 12);
        $c = json_decode((string)Db::setting($key), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 6 * 3600) {
            return self::$apps[$ref] = $c['app'];
        }
        if (ctype_digit($ref)) {
            $a = self::call('GET', '/app/' . $ref);
        } else {
            [$org, $space, $app] = array_pad(explode('/', trim($ref, '/')), 3, '');
            $a = self::call('GET', '/app/org/' . rawurlencode($org) . '/space/' . rawurlencode($space) . '/' . rawurlencode($app));
        }
        $keep = ['app_id' => (int)$a['app_id'], 'space_id' => (int)($a['space_id'] ?? 0), 'name' => (string)($a['config']['name'] ?? ''), 'link' => (string)($a['link'] ?? ''), 'fields' => []];
        foreach ($a['fields'] ?? [] as $f) {
            if (($f['status'] ?? 'active') !== 'active') {
                continue;
            }
            $s = $f['config']['settings'] ?? [];
            $keep['fields'][] = [
                'id' => (int)$f['field_id'], 'external_id' => (string)$f['external_id'], 'type' => (string)$f['type'],
                'label' => (string)($f['label'] ?? $f['config']['label'] ?? ''), 'required' => (bool)($f['config']['required'] ?? false),
                'options' => array_values(array_map(static fn($o) => ['id' => (int)$o['id'], 'text' => (string)$o['text']], array_filter($s['options'] ?? [], static fn($o) => ($o['status'] ?? 'active') === 'active'))),
                'multiple' => (bool)($s['multiple'] ?? false),
                'apps' => array_values(array_map(static fn($ra) => (int)($ra['app_id'] ?? $ra), $s['referenced_apps'] ?? [])),
            ];
        }
        Db::setSetting($key, (string)json_encode(['at' => time(), 'app' => $keep]));
        return self::$apps[$ref] = $keep;
    }

    public static function norm(string $label): string
    {
        return strtolower(trim((string)preg_replace('/\s+/', ' ', $label)));
    }

    /** The app's field with this label (case and spacing ignored), or null. */
    public static function field(array $app, string $label): ?array
    {
        foreach ($app['fields'] as $f) {
            if (self::norm($f['label']) === self::norm($label)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Builds the "fields" payload from label => value. A category value may be a list of candidate option texts
     * (the first one the app has wins). Returns [fields, warnings]. $lookup resolves contact/app references
     * (injected so the conversion is testable without the network).
     *
     * @param array<string,mixed> $values
     * @return array{0:array<string,mixed>,1:string[]}
     */
    public static function fields(array $app, array $values, ?callable $lookup = null): array
    {
        $out = [];
        $warn = [];
        foreach ($values as $label => $v) {
            if ($v === null || $v === '' || $v === [] || $v === ['_all' => []]) {
                continue;
            }
            $f = self::field($app, (string)$label);
            if (!$f) {
                $warn[] = "No \"$label\" field in {$app['name']}";
                continue;
            }
            $val = self::convert($f, $v, $lookup);
            if ($val === null) {
                $warn[] = "\"$label\": could not use " . (is_array($v) ? implode(' / ', array_map(static fn($x) => is_array($x) ? implode(', ', $x) : (string)$x, $v)) : (string)$v) . " ({$f['type']} field)";
                continue;
            }
            $out[$f['external_id']] = $val;
        }
        return [$out, $warn];
    }

    private static function convert(array $f, mixed $v, ?callable $lookup): mixed
    {
        // ['_all' => [...]]: every matching option of a multi-select category (the first match if single-select)
        if (is_array($v) && isset($v['_all'])) {
            if ($f['type'] !== 'category' || !$v['_all']) {
                return null;
            }
            $ids = [];
            foreach ($v['_all'] as $want) {
                foreach ($f['options'] as $o) {
                    if (self::norm($o['text']) === self::norm((string)$want)) {
                        $ids[$o['id']] = $o['id'];
                    }
                }
            }
            $ids = array_values($ids);
            return $ids ? ($f['multiple'] ? $ids : [$ids[0]]) : null;
        }
        $first = is_array($v) ? reset($v) : $v;
        switch ($f['type']) {
            case 'text':
                return (string)$first; // candidate lists: the first is the preferred wording
            case 'number':
            case 'progress':
                return is_numeric($first) ? (string)(0 + $first) : null;
            case 'money':
                return is_numeric($first) ? ['value' => (string)round((float)$first, 2), 'currency' => 'USD'] : null;
            case 'category':
                $want = array_map(static fn($x) => self::norm((string)$x), is_array($v) ? $v : [$v]);
                foreach ($want as $w) {
                    foreach ($f['options'] as $o) {
                        if (self::norm($o['text']) === $w) {
                            return [$o['id']];
                        }
                    }
                }
                return null;
            case 'date':
                $t = strtotime((string)$first);
                return $t ? ['start' => date('Y-m-d H:i:s', $t)] : null;
            case 'location':
                return (string)$first;
            case 'phone':
                $d = substr(preg_replace('/\D/', '', (string)$first) ?? '', -10);
                return strlen($d) === 10 ? [['type' => 'mobile', 'value' => '+1' . $d]] : null;
            case 'email':
                return str_contains((string)$first, '@') ? [['type' => 'work', 'value' => (string)$first]] : null;
            case 'app':
                if (is_int($first) || ctype_digit((string)$first)) {
                    return [(int)$first];
                }
                $id = $lookup ? $lookup('app', $f, (string)$first) : null;
                return $id ? [$id] : null;
            case 'contact':
                $id = $lookup ? $lookup('contact', $f, (string)$first) : null;
                return $id ? [$id] : null;
            default:
                return null; // calculation, image, embed, …: never written
        }
    }

    /** Resolves a contact (workspace member) or an item in a referenced app by its name/title. */
    public static function lookup(string $kind, array $field, string $name, int $spaceId): ?int
    {
        $want = self::norm($name);
        if ($kind === 'contact') {
            foreach (self::call('GET', '/space/' . $spaceId . '/member/?limit=500') as $m) {
                $p = $m['profile'] ?? [];
                if (self::norm((string)($p['name'] ?? '')) === $want) {
                    return (int)$p['profile_id'];
                }
            }
            return null;
        }
        foreach ($field['apps'] as $appId) {
            foreach (self::call('POST', '/search/app/' . $appId . '/?limit=20', ['query' => $name, 'ref_type' => 'item']) as $hit) {
                if (self::norm((string)($hit['title'] ?? '')) === $want) {
                    return (int)$hit['id'];
                }
            }
        }
        return null;
    }

    /** Item ids in an app whose search text matches the phone (any common format), verified by the item's digits. */
    public static function findByPhone(int $appId, string $phone10): ?int
    {
        $formats = [$phone10, substr($phone10, 0, 3) . ' ' . substr($phone10, 3, 3) . ' ' . substr($phone10, 6), '(' . substr($phone10, 0, 3) . ') ' . substr($phone10, 3, 3) . '-' . substr($phone10, 6)];
        foreach ($formats as $q) {
            foreach (self::call('POST', '/search/app/' . $appId . '/?limit=10', ['query' => $q, 'ref_type' => 'item']) as $hit) {
                $item = self::call('GET', '/item/' . (int)$hit['id']);
                foreach ($item['fields'] ?? [] as $f) {
                    foreach ($f['values'] ?? [] as $val) {
                        $raw = is_array($val['value'] ?? null) ? '' : (string)($val['value'] ?? '');
                        if (str_ends_with(preg_replace('/\D/', '', $raw) ?? '', $phone10)) {
                            return (int)$item['item_id'];
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @return array{item_id:int, link:string} */
    public static function create(int $appId, array $fields): array
    {
        $r = self::call('POST', '/item/app/' . $appId . '/', ['fields' => $fields]);
        $id = (int)($r['item_id'] ?? 0);
        $link = (string)($r['link'] ?? '');
        if ($id && $link === '') {
            $link = (string)(self::call('GET', '/item/' . $id)['link'] ?? '');
        }
        return ['item_id' => $id, 'link' => $link];
    }

    public static function comment(int $itemId, string $text): void
    {
        self::call('POST', '/comment/item/' . $itemId . '/', ['value' => $text]);
    }
}
