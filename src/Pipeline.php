<?php
declare(strict_types=1);

namespace Leads;

/**
 * Lead pipeline: status, notes timeline, and exclusive delivery to clients.
 * Statuses are 4Return's view of the deal, including outcomes a client reports after delivery
 * (kept so scoring can later be tuned on real results).
 */
final class Pipeline
{
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'appointment' => 'Appointment',
        'offer' => 'Offer',
        'under_contract' => 'Under contract',
        'closed' => 'Closed',
        'dead' => 'Dead / not interested',
    ];

    public const CHANNELS = ['podio' => 'Podio', 'email' => 'Email', 'csv' => 'CSV file', 'other' => 'Other'];

    public static function label(?string $status): string
    {
        return self::STATUSES[$status ?? 'new'] ?? (string)$status;
    }

    /** Saves a status change and/or a note in one go. Returns what changed, for the flash message. */
    public static function update(string $pid, ?string $status, string $note, array $user): string
    {
        $p = Db::one('SELECT status FROM properties WHERE parcel_id = ?', [$pid]);
        if (!$p) {
            throw new \InvalidArgumentException('Lead not found.');
        }
        $note = trim($note);
        $done = [];
        if ($status !== null && $status !== $p['status']) {
            if (!isset(self::STATUSES[$status])) {
                throw new \InvalidArgumentException('Unknown status.');
            }
            Db::run('UPDATE properties SET status = ?, status_at = UTC_TIMESTAMP() WHERE parcel_id = ?', [$status, $pid]);
            self::event($pid, 'status', $user, null, $p['status'], $status, $note !== '' ? $note : null);
            Auth::audit('lead_status', "$pid {$p['status']} -> $status");
            $done[] = 'status ' . self::label($status);
        } elseif ($note !== '') {
            self::event($pid, 'note', $user, null, null, null, $note);
            $done[] = 'note added';
        }
        return $done ? ucfirst(implode(', ', $done)) . '.' : 'Nothing to save.';
    }

    /** Bulk status change from the Leads page. Returns the number of leads changed. */
    public static function bulkStatus(array $pids, string $status, array $user): int
    {
        if (!isset(self::STATUSES[$status]) || !$pids) {
            return 0;
        }
        $n = 0;
        foreach (self::existing($pids) as $p) {
            if ($p['status'] === $status) {
                continue;
            }
            Db::run('UPDATE properties SET status = ?, status_at = UTC_TIMESTAMP() WHERE parcel_id = ?', [$status, $p['parcel_id']]);
            self::event($p['parcel_id'], 'status', $user, null, $p['status'], $status, null);
            $n++;
        }
        Auth::audit('lead_status_bulk', "$n -> $status");
        return $n;
    }

    /**
     * Marks leads delivered to a client: exclusive to that client from now on.
     * Leads already exclusive to another client are skipped. Returns [delivered, skipped].
     *
     * @return array{0:int,1:int}
     */
    public static function deliver(array $pids, int $clientId, string $channel, string $note, array $user): array
    {
        if (!isset(self::CHANNELS[$channel])) {
            throw new \InvalidArgumentException('Pick a delivery channel.');
        }
        $client = Db::one('SELECT id, name FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new \InvalidArgumentException('Unknown client.');
        }
        $ok = $skipped = 0;
        foreach (self::existing($pids) as $p) {
            if ($p['exclusive_client_id'] !== null && (int)$p['exclusive_client_id'] !== $clientId) {
                $skipped++;
                continue;
            }
            Db::run(
                'UPDATE properties SET exclusive_client_id = ?, delivered_at = UTC_TIMESTAMP(), delivery_channel = ? WHERE parcel_id = ?',
                [$clientId, $channel, $p['parcel_id']]
            );
            self::event($p['parcel_id'], 'delivered', $user, $clientId, null, null,
                trim('Via ' . self::CHANNELS[$channel] . '. ' . trim($note)));
            $ok++;
        }
        Auth::audit('lead_delivered', "$ok to {$client['name']} via $channel" . ($skipped ? ", $skipped skipped" : '') . ' :: '
            . implode(',', array_slice($pids, 0, 20)));
        return [$ok, $skipped];
    }

    /** Admin only: ends a lead's exclusivity so it returns to 4Return's pool. */
    public static function release(string $pid, string $note, array $user): void
    {
        $p = Db::one('SELECT exclusive_client_id FROM properties WHERE parcel_id = ?', [$pid]);
        if (!$p || $p['exclusive_client_id'] === null) {
            return;
        }
        Db::run('UPDATE properties SET exclusive_client_id = NULL, delivered_at = NULL, delivery_channel = NULL WHERE parcel_id = ?', [$pid]);
        self::event($pid, 'released', $user, (int)$p['exclusive_client_id'], null, null, trim($note) ?: null);
        Auth::audit('lead_released', $pid);
    }

    public static function events(string $pid): array
    {
        return Db::all(
            'SELECT e.*, u.email, c.name AS client_name FROM lead_events e LEFT JOIN users u ON u.id = e.user_id
             LEFT JOIN clients c ON c.id = e.client_id WHERE e.parcel_id = ? ORDER BY e.id DESC LIMIT 200',
            [$pid]
        );
    }

    /**
     * May $user skip trace / push this lead? Only if it is in the pool or exclusive to the user's own client.
     * (Admins belong to 4Return, so a lead delivered to Home Detroit is read-only for them.)
     */
    public static function canWork(array $p, array $user): bool
    {
        return $p['exclusive_client_id'] === null || (int)$p['exclusive_client_id'] === (int)($user['client_id'] ?? 0);
    }

    /** May $user change status / add notes? Admins always (they record client outcomes); others as canWork. */
    public static function canEdit(array $p, array $user): bool
    {
        return $user['role'] === 'admin' || self::canWork($p, $user);
    }

    /** Filters parcel ids down to the ones $user may skip trace / push. */
    public static function workable(array $pids, array $user): array
    {
        return array_column(array_filter(self::existing($pids), static fn($p) => self::canWork($p, $user)), 'parcel_id');
    }

    private static function existing(array $pids): array
    {
        $pids = array_values(array_unique(array_map('strval', $pids)));
        if (!$pids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($pids), '?'));
        return Db::all("SELECT parcel_id, status, exclusive_client_id FROM properties WHERE parcel_id IN ($in)", $pids);
    }

    private static function event(string $pid, string $kind, array $user, ?int $clientId, ?string $old, ?string $new, ?string $body): void
    {
        Db::run(
            'INSERT INTO lead_events (parcel_id, user_id, client_id, kind, old_status, new_status, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$pid, $user['id'] ?? null, $clientId, $kind, $old, $new, $body !== null ? mb_substr($body, 0, 5000) : null]
        );
    }
}
