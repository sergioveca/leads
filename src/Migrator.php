<?php
declare(strict_types=1);

namespace Leads;

/**
 * Applies migrations/NNN_name.sql in order, once each. Runs automatically on every request/cron
 * (cheap check), so a Git deploy is all that is needed to update the schema.
 * Also creates the first admin user from ADMIN_EMAIL / ADMIN_INITIAL_PASSWORD when no users exist.
 */
final class Migrator
{
    public static function ensure(): void
    {
        if (Env::get('DB_DATABASE') === null) {
            return; // not configured yet; setup page will explain
        }
        $files = glob(LEADS_ROOT . '/migrations/*.sql') ?: [];
        sort($files);
        $latest = $files ? basename(end($files)) : '';
        $stamp = LEADS_ROOT . '/storage/cache/migrated.txt';
        if (is_file($stamp) && trim((string)file_get_contents($stamp)) === $latest) {
            return;
        }

        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = array_flip(array_column(Db::all('SELECT name FROM migrations'), 'name'));
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($done[$name])) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            foreach (self::split($sql) as $stmt) {
                $pdo->exec($stmt);
            }
            Db::run('INSERT INTO migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP())', [$name]);
            Log::info("Migration applied: $name");
        }
        self::seedAdmin();
        if ((int)Db::val('SELECT COUNT(*) FROM users') > 0) {
            @file_put_contents($stamp, $latest); // keep re-checking until the first admin exists
        }
    }

    private static function split(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql . "\n")))));
    }

    private static function seedAdmin(): void
    {
        if ((int)Db::val('SELECT COUNT(*) FROM users') > 0) {
            return;
        }
        $email = strtolower(trim((string)Env::get('ADMIN_EMAIL', '')));
        $pass = (string)Env::get('ADMIN_INITIAL_PASSWORD', '');
        if ($email === '' || $pass === '' || str_starts_with($pass, 'FILL')) {
            return;
        }
        $clientId = (int)Db::val("SELECT id FROM clients WHERE name = '4Return'");
        Db::run(
            'INSERT INTO users (email, name, password_hash, role, client_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$email, 'Admin', password_hash($pass, PASSWORD_DEFAULT), 'admin', $clientId ?: null]
        );
        Log::info("Admin user created: $email");
    }
}
