<?php
declare(strict_types=1);

namespace Leads;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('leads_sid');
        session_save_path(LEADS_ROOT . '/storage/cache');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        if (!empty($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 8 * 3600) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();
    }

    public static function user(): ?array
    {
        static $user = false;
        if ($user === false) {
            $id = (int)($_SESSION['uid'] ?? 0);
            $user = $id ? Db::one('SELECT u.*, c.name AS client_name FROM users u LEFT JOIN clients c ON c.id = u.client_id WHERE u.id = ?', [$id]) : null;
        }
        return $user;
    }

    public static function require(bool $admin = false): array
    {
        $u = self::user();
        if (!$u) {
            header('Location: /login');
            exit;
        }
        if ($admin && $u['role'] !== 'admin') {
            http_response_code(403);
            exit('Admins only');
        }
        return $u;
    }

    public static function attempt(string $email, string $password): ?string
    {
        $u = Db::one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$u) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // timing
            return 'Wrong email or password.';
        }
        if ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time()) {
            return 'Too many attempts. Try again in 15 minutes.';
        }
        if (!password_verify($password, $u['password_hash'])) {
            $fails = (int)$u['failed_logins'] + 1;
            Db::run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $fails >= 8 ? gmdate('Y-m-d H:i:s', time() + 900) : null, $u['id']]);
            return 'Wrong email or password.';
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        Db::run('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        self::audit('login', null, (int)$u['id'], $u['client_id'] ? (int)$u['client_id'] : null);
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
            http_response_code(419);
            exit('Session expired. Go back and try again.');
        }
    }

    public static function audit(string $action, ?string $details = null, ?int $userId = null, ?int $clientId = null): void
    {
        $u = $userId === null ? self::user() : null;
        Db::run(
            'INSERT INTO audit_log (user_id, client_id, action, details, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$userId ?? ($u['id'] ?? null), $clientId ?? ($u['client_id'] ?? null), $action, $details ? mb_substr($details, 0, 1000) : null, $_SERVER['REMOTE_ADDR'] ?? 'cli']
        );
    }
}
