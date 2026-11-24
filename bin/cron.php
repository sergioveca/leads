<?php
declare(strict_types=1);

/*
 * Scheduled task entry point. In Plesk: Scheduled Tasks > Add Task > "Run a PHP script",
 * script path: leads.4-return.com/bin/cron.php , run every 10 minutes.
 * Optional argument: time budget in seconds (default 240 for CLI).
 */

require dirname(__DIR__) . '/bootstrap.php';

$budget = isset($argv[1]) ? max(20, (int)$argv[1]) : \Leads\Env::int('CRON_BUDGET_SECONDS', 240);
$log = (new \Leads\Jobs($budget))->run();
foreach ($log as $line) {
    echo $line, PHP_EOL;
}
