<?php
declare(strict_types=1);

namespace Leads;

/**
 * "New Podio Seller": turns a positive SMS reply into a Contact + Seller Lead in Home Detroit's Podio,
 * filled with everything Leads knows about the property, plus the seller's reply as a comment.
 * draft() prepares an editable review form; create() sends what the reviewer approved.
 */
final class PodioSeller
{
    /** Draft field => form label, in the order the review page shows them. */
    public const FORM = [
        'name' => 'Seller name', 'address' => 'Property address', 'lead_came_in' => 'Date lead came in',
        'beds' => 'Bedroom count', 'baths' => 'Bathroom count', 'sqft' => 'SqFt', 'property_type' => 'Property type',
        'years_owned' => 'Years owned', 'occupancy' => 'Owner / tenant / vacant', 'paid_off' => 'Property paid off?',
        'mortgage' => 'Mortgage amount', 'value' => 'Property value (TotalView)', 'county' => 'County', 'apn' => 'APN',
        'list_type' => 'List type', 'motivation' => 'Motivation', 'notes' => 'Miscellaneous notes', 'reply' => 'Comment (seller\'s reply)',
    ];

    /** Leads motivator => Home Detroit's "List Type" option, most telling first. */
    public const LIST_TYPES = [
        'sheriff_sale' => 'Sheriff Sales', 'estate_owner' => 'Probate', 'inherited' => 'Inheritance',
        'tax_pending' => 'Tax Default', 'tax_multi' => 'Tax Default', 'tax_big' => 'Tax Default', 'tax' => 'Tax Default',
        'blight' => 'Code Violations', 'rental_noncompliant' => 'Code Violations', 'demolition' => 'Code Violations',
        'vacant' => 'Vacant', 'absentee' => 'Absentee Owners', 'out_of_state' => 'Absentee Owners',
        'high_equity' => 'High Equity', 'free_clear' => 'High Equity',
    ];

    /** Years since the last sale as Home Detroit's "Years Owned" bracket. */
    public static function yearsBracket(int $years): string
    {
        return match (true) {
            $years < 5 => 'Less Than 5', $years < 10 => '5 To 10', $years < 15 => '10 To 15', $years < 20 => '15 To 20', default => '20+',
        };
    }

    public static function sellerApp(): string
    {
        return (string)Env::get('PODIO_SELLER_APP', 'detroitprogresscom/dpgr-crm/seller-leads');
    }

    public static function contactsApp(): string
    {
        return (string)Env::get('PODIO_CONTACTS_APP', 'detroitprogresscom/dpgr-crm/contacts');
    }

    public static function phone10(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        return strlen($d) === 11 && $d[0] === '1' ? substr($d, 1) : substr($d, -10);
    }

    /** Properties whose traced owner has this phone, best first. */
    public static function properties(string $phone10): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT p.*, c.full_name, c.first_name, c.last_name, c.contact_type
             FROM phones ph JOIN contacts c ON c.id = ph.contact_id JOIN properties p ON p.parcel_id = c.parcel_id
             WHERE ph.number = ? ORDER BY (c.contact_type = \'owner\') DESC, p.score DESC',
            [$phone10]
        ) as $r) {
            $out[$r['parcel_id']] ??= $r;
        }
        return array_values($out);
    }

    /** "15517 Lauder St, Detroit…" vs "15517 LAUDER": same house number and first street word. */
    public static function sameStreet(string $a, string $b): bool
    {
        $k = static function (string $s): string {
            $w = preg_split('/\s+/', strtoupper(trim(explode(',', $s)[0]))) ?: [];
            $w = array_values(array_filter($w, static fn($x) => !in_array($x, ['N', 'S', 'E', 'W'], true)));
            return ($w[0] ?? '') . ' ' . ($w[1] ?? '');
        };
        return trim($k($a)) !== '' && $k($a) === $k($b);
    }

    /**
     * @param array $messages SMS thread (direction, body, created_at) from the SMS app
     * @return array{draft:array, choices:array, pushed:?array, messages:array}
     */
    public static function draft(string $phone10, ?string $pid = null, string $addressHint = '', array $messages = []): array
    {
        $choices = self::properties($phone10);
        $p = null;
        foreach ($choices as $c) {
            if (($pid !== null && $c['parcel_id'] === $pid) || ($pid === null && $addressHint !== '' && (self::sameStreet($addressHint, (string)$c['address']) || self::sameStreet($addressHint, (string)$c['dm_address'])))) {
                $p = $c;
                break;
            }
        }
        $p ??= $choices[0] ?? null;
        $pushed = Db::one('SELECT * FROM podio_pushes WHERE phone = ? ORDER BY id DESC LIMIT 1', [$phone10]);
        $inbound = array_values(array_filter($messages, static fn($m) => ($m['direction'] ?? '') === 'inbound' && trim((string)($m['body'] ?? '')) !== ''));
        $lastIn = $inbound ? end($inbound) : null;
        $d = ['phone' => $phone10, 'parcel_id' => $p['parcel_id'] ?? ''];
        $d['reply'] = implode("\n", array_map(static fn($m) => 'Reply: "' . trim((string)$m['body']) . '"', $inbound));
        $d['lead_came_in'] = $lastIn ? date('Y-m-d H:i', (int)strtotime($lastIn['created_at'] . ' UTC')) : date('Y-m-d H:i');
        if (!$p) {
            $d['name'] = '';
            $d['address'] = $addressHint;
            return ['draft' => $d + array_fill_keys(array_keys(self::FORM), ''), 'choices' => [], 'pushed' => $pushed, 'messages' => $messages];
        }
        $sigRows = Db::all('SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE parcel_id = ? AND active = 1', [$p['parcel_id']]);
        $sig = [];
        foreach ($sigRows as $s) {
            $sig[$s['type']][] = $s;
        }
        $money = static fn($v): string => '$' . number_format((float)$v);
        $name = trim((string)($p['full_name'] ?: trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))));
        $loan = $p['dm_loan_balance'];
        $occupancy = '';
        if ($p['dm_owner_occupied'] !== null && (int)$p['dm_owner_occupied'] === 1) {
            $occupancy = 'Yes - Owner Occupied';
        } elseif (isset($sig['vacant'])) {
            $occupancy = 'Vacant';
        } elseif (isset($sig['rental']) || isset($sig['rental_cert'])) {
            $occupancy = 'Yes - Tenant Occupied';
        }
        $years = $p['sale_date'] ? (string)max(0, (int)floor((time() - (int)strtotime((string)$p['sale_date'])) / (365.25 * 86400))) : '';
        $backTaxes = isset($sig['tax_forfeiture']) ? max(array_map(static fn($s) => (float)$s['amount'], $sig['tax_forfeiture'])) : 0;
        $keys = array_diff(Motivators::evaluate($p, $sigRows), ['individual']);
        $labels = array_map([Motivators::class, 'label'], $keys);
        $listTypes = [];
        foreach (self::LIST_TYPES as $k => $opt) {
            if (in_array($k, $keys, true)) {
                $listTypes[$opt] = true;
            }
        }
        if ($p['dm_equity_pct'] !== null && (float)$p['dm_equity_pct'] < 0) {
            $listTypes['Negative Equity'] = true;
        }
        $leadsList = (string)(Db::val('SELECT list_name FROM sms_export_log WHERE phone = ? ORDER BY exported_at DESC LIMIT 1', [$phone10]) ?? '');
        $base = 'https://leads.4-return.com';
        $notes = array_filter([
            '4Return Leads score ' . (int)$p['score'] . ': ' . $base . '/lead?id=' . rawurlencode((string)$p['parcel_id']),
            $p['dm_estimated_value'] !== null ? 'Est. value ' . $money($p['dm_estimated_value']) . ($p['dm_equity_pct'] !== null ? ' · equity ' . (int)$p['dm_equity_pct'] . '%' : '') . ($loan !== null ? ' · loan ' . $money($loan) : '') . ' (DealMachine)' : null,
            $backTaxes > 0 ? 'Back taxes owed: ' . $money($backTaxes) : null,
            $leadsList !== '' ? 'Leads list: ' . $leadsList : null,
            $p['sale_date'] ? 'Last sale: ' . $p['sale_date'] . ($years !== '' ? " ($years years ago)" : '') . ($p['sale_price'] ? ' for ' . $money($p['sale_price']) : '') : null,
            $p['owner1'] ? 'Owner of record: ' . $p['owner1'] . ($p['mail_address'] ? ' · mailing ' . trim($p['mail_address'] . ', ' . $p['mail_city'] . ' ' . $p['mail_state'] . ' ' . $p['mail_zip']) : '') : null,
            $p['year_built'] || $p['dm_year_built'] ? 'Year built: ' . ($p['dm_year_built'] ?? $p['year_built']) : null,
        ]);
        $d += [
            'name' => ucwords(strtolower($name), " -'"),
            'address' => Exports::fullAddress($p),
            'beds' => $p['dm_beds'] !== null ? (string)(0 + $p['dm_beds']) : '',
            'baths' => $p['dm_baths'] !== null ? (string)(0 + $p['dm_baths']) : '',
            'sqft' => (string)($p['dm_sqft'] ?? $p['building_sqft'] ?? ''),
            'property_type' => stripos((string)$p['property_class'], 'RESID') !== false ? 'Single Family' : '',
            'years_owned' => $years !== '' ? self::yearsBracket((int)$years) : '',
            'occupancy' => $occupancy,
            'paid_off' => $loan === null ? '' : ((int)$loan === 0 ? 'Yes' : 'No'),
            'mortgage' => $loan !== null && (int)$loan > 0 ? (string)$loan : '',
            'value' => (string)($p['dm_estimated_value'] ?? ''),
            'county' => ucfirst(strtolower((string)$p['county'])),
            'apn' => (string)($p['apn'] ?: $p['parcel_id']),
            'list_type' => implode(', ', array_keys($listTypes)),
            'motivation' => trim(implode(', ', $labels) . "\n" . (string)$p['score_reasons']),
            'notes' => implode("\n", $notes),
        ];
        return ['draft' => $d, 'choices' => $choices, 'pushed' => $pushed, 'messages' => $messages];
    }

    /** Podio label => value for the Contacts app. */
    public static function contactValues(array $d): array
    {
        return [
            'Complete Name' => $d['name'], 'Phone Number' => $d['phone'], 'Contact Type' => 'Seller', 'Address' => $d['address'],
        ];
    }

    /** Podio label => value for the Seller Leads app. Lists are category candidates (the first option Podio has wins). */
    public static function sellerValues(array $d, int $contactItemId): array
    {
        $yes = static fn(string $v) => $v === 'Yes' ? ['Yes', 'Paid Off', 'Paid off'] : ($v === 'No' ? ['No', 'Not Paid Off'] : $v);
        return [
            'Seller Contact' => $contactItemId,
            'Lead Manager' => (string)Env::get('PODIO_LEAD_MANAGER', 'Tom ACQ'),
            'Lead Source' => (string)Env::get('PODIO_LEAD_SOURCE', '4Return - County Leads APP'),
            'Lead Status' => (string)Env::get('PODIO_LEAD_STATUS', '#New Untouched#'),
            'Property Address Map' => $d['address'],
            'Date Lead Came In' => $d['lead_came_in'],
            'Bedroom count' => $d['beds'],
            'Bathroom count' => $d['baths'],
            'SqFt' => $d['sqft'],
            'Property Type' => $d['property_type'] === 'Single Family' ? ['Single Family', 'Single Family Home', 'SFH', 'House', 'Residential'] : $d['property_type'],
            'Years Owned' => $d['years_owned'],
            'Is the property currently owner occupied, tenant occupied or vacant?' => $d['occupancy'],
            'Property Paid off?' => $yes($d['paid_off']),
            'Mortgage Amount' => $d['mortgage'],
            'Property Value (TotalView)' => $d['value'],
            'County' => $d['county'],
            '++APN' => $d['apn'],
            'List Type' => ['_all' => array_values(array_filter(array_map('trim', explode(',', $d['list_type']))))],
            'Motivation' => $d['motivation'],
            'Miscellaneous Notes' => $d['notes'],
        ];
    }

    /**
     * Sends the approved draft: finds or creates the Contact, creates the Seller Lead, adds the reply comment,
     * records the push and sets the lead to Contacted (so it leaves future SMS exports).
     *
     * @return array{url:string, contact_url:string, warnings:string[], reused_contact:bool}
     */
    public static function create(array $d, array $user): array
    {
        if (!Podio::configured()) {
            throw new \RuntimeException('Podio is not connected yet: add PODIO_CLIENT_ID, PODIO_CLIENT_SECRET, PODIO_USERNAME and PODIO_PASSWORD to the server .env.');
        }
        $phone = self::phone10((string)$d['phone']);
        if (strlen($phone) !== 10 || trim((string)$d['name']) === '') {
            throw new \InvalidArgumentException('A seller name and a 10-digit phone are required.');
        }
        $contacts = Podio::app(self::contactsApp());
        $seller = Podio::app(self::sellerApp());
        $warn = [];

        $contactId = (int)(Db::val('SELECT contact_item_id FROM podio_pushes WHERE phone = ? AND contact_item_id IS NOT NULL ORDER BY id DESC LIMIT 1', [$phone]) ?? 0);
        $contactId = $contactId ?: (int)(Podio::findByPhone($contacts['app_id'], $phone) ?? 0);
        $reused = $contactId > 0;
        $contactUrl = '';
        if (!$reused) {
            [$cf, $cw] = Podio::fields($contacts, self::contactValues($d));
            $warn = array_merge($warn, $cw);
            $c = Podio::create($contacts['app_id'], $cf);
            $contactId = $c['item_id'];
            $contactUrl = $c['link'];
        }

        $lookup = static fn(string $kind, array $field, string $name) => Podio::lookup($kind, $field, $name, $seller['space_id']);
        [$sf, $sw] = Podio::fields($seller, self::sellerValues($d, $contactId), $lookup);
        $warn = array_merge($warn, $sw);
        $s = Podio::create($seller['app_id'], $sf);
        if (trim((string)$d['reply']) !== '') {
            try {
                Podio::comment($s['item_id'], trim((string)$d['reply']));
            } catch (\Throwable $e) {
                $warn[] = 'Comment not added: ' . $e->getMessage();
            }
        }

        Db::run(
            'INSERT INTO podio_pushes (parcel_id, phone, contact_item_id, seller_item_id, seller_url, warnings, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [(string)$d['parcel_id'], $phone, $contactId, $s['item_id'], $s['link'], $warn ? implode("\n", $warn) : null, $user['id'] ?? null]
        );
        if ((string)$d['parcel_id'] !== '') {
            $status = (string)Db::val('SELECT status FROM properties WHERE parcel_id = ?', [$d['parcel_id']]);
            Pipeline::update((string)$d['parcel_id'], $status === 'new' ? 'contacted' : null, 'Sent to Home Detroit Podio: ' . $s['link'], $user);
        }
        Auth::audit('podio_push', $d['parcel_id'] . ' ' . $phone . ' ' . $s['link']);
        self::notifySms($phone, $s['link'], (string)$d['name']);
        return ['url' => $s['link'], 'contact_url' => $contactUrl, 'warnings' => $warn, 'reused_contact' => $reused];
    }

    /** The SMS thread from sms.4-return.com's read-only bridge ([] when not configured or unreachable). */
    public static function messages(string $phone10): array
    {
        $token = (string)Env::get('SMS_HISTORY_BRIDGE_TOKEN', '');
        if ($token === '') {
            return [];
        }
        try {
            $r = Http::request('GET', rtrim((string)Env::get('SMS_APP_URL', 'https://sms.4-return.com'), '/') . '/api/bridge/conversations/' . rawurlencode('+1' . $phone10) . '/messages',
                ['Accept: application/json', 'x-4return-bridge-token: ' . $token], null, 15);
            $data = json_decode($r['body'], true);
            return $r['status'] === 200 && is_array($data['messages'] ?? null) ? $data['messages'] : [];
        } catch (\Throwable $e) {
            Log::error('SMS bridge: ' . $e->getMessage());
            return [];
        }
    }

    /** Tells the SMS app, which posts "In Podio" into the Slack thread (best effort; the SMS app may not support it yet). */
    private static function notifySms(string $phone10, string $url, string $name): void
    {
        $token = (string)Env::get('SMS_HISTORY_BRIDGE_TOKEN', '');
        if ($token === '' || $url === '') {
            return;
        }
        try {
            Http::request('POST', rtrim((string)Env::get('SMS_APP_URL', 'https://sms.4-return.com'), '/') . '/api/bridge/podio-created',
                ['Content-Type: application/json', 'x-4return-bridge-token: ' . $token], (string)json_encode(['phone' => '+1' . $phone10, 'url' => $url, 'name' => $name]), 10);
        } catch (\Throwable $e) {
            Log::error('SMS bridge podio-created: ' . $e->getMessage());
        }
    }
}
