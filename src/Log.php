<?php
declare(strict_types=1);

namespace Leads;

final class Log
{
    public static function info(string $msg): void
    {
        self::write('INFO', $msg);
    }

    public static function error(string $msg): void
    {
        self::write('ERROR', $msg);
    }

    private static function write(string $level, string $msg): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $msg);
        @file_put_contents(LEADS_ROOT . '/storage/logs/leads-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli') {
            echo $line;
        }
    }
}
