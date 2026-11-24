<?php
declare(strict_types=1);

namespace Leads;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', 'localhost'),
                Env::int('DB_PORT', 3306),
                Env::get('DB_DATABASE', 'leads')
            );
            self::$pdo = new PDO($dsn, (string)Env::get('DB_USERNAME', ''), (string)Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $r = self::run($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    /** Multi-row INSERT ... ON DUPLICATE KEY UPDATE for $rows (list of assoc arrays with identical keys). */
    public static function upsert(string $table, array $rows, array $updateCols): int
    {
        if (!$rows) {
            return 0;
        }
        $cols = array_keys($rows[0]);
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES '
            . implode(',', array_fill(0, count($rows), $place));
        if ($updateCols) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(
                static fn($c) => "`$c`=VALUES(`$c`)",
                $updateCols
            ));
        }
        $params = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $params[] = $r[$c] ?? null;
            }
        }
        return self::run($sql, $params)->rowCount();
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $v = self::val('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::run('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
    }
}
