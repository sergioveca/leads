<?php
declare(strict_types=1);

/*
 * Leads bootstrap: loads .env, autoloads src/, connects the DB and applies pending migrations.
 * Used by public/index.php (web) and bin/cron.php (Scheduled Tasks).
 */

define('LEADS_ROOT', __DIR__);
define('LEADS_VERSION', '1.6.1');

date_default_timezone_set('America/New_York'); // Miami time (Eastern), shown with EST/EDT
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Leads\\', 6) !== 0) {
        return;
    }
    $file = LEADS_ROOT . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach (['storage', 'storage/logs', 'storage/cache'] as $dir) {
    $path = LEADS_ROOT . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

\Leads\Env::load(LEADS_ROOT . '/.env');

set_exception_handler(static function (\Throwable $e): void {
    \Leads\Log::error('Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $token = (string)\Leads\Env::get('HEALTH_TOKEN', '');
    $debug = \Leads\Env::bool('APP_DEBUG', false)
        || ($token !== '' && hash_equals($token, (string)($_GET['token'] ?? '')));
    echo '<h1>Something went wrong</h1>';
    if ($debug) {
        echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        echo '<p>The error was logged. Please try again in a minute.</p>';
    }
});

\Leads\Migrator::ensure();
