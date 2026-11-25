<?php
declare(strict_types=1);

namespace Leads;

use Leads\Sources\DetroitBlight;
use Leads\Sources\DetroitSignals;
use Leads\Sources\DetroitParcels;
use Leads\Sources\MacombParcels;
use Leads\Sources\Treasurer;

/**
 * Scheduled work, run in time-boxed slices so it fits shared-hosting limits.
 * Every job is resumable (state in job_state), so a run can stop anywhere and continue next time.
 *
 * Order per run: Treasurer CSV import -> Treasurer page watch (daily) -> blight pass (daily, paged)
 * -> other Detroit signal passes (daily, paged, max 60 s each per run: see Sources\DetroitSignals)
 * -> Detroit parcel sync (owner/mailing) -> scoring -> saved-list counts (hourly) -> auto skip trace
 * -> DNC re-check (daily).
 */
final class Jobs
{
    private float $deadline;
    private array $log = [];

    public function __construct(int $budgetSeconds)
    {
        $this->deadline = microtime(true) + $budgetSeconds;
        @set_time_limit($budgetSeconds + 30);
    }

    public static function lock(): bool
    {
        $got = Db::val("SELECT GET_LOCK('leads_jobs', 0)");
        return (int)$got === 1;
    }

    public function left(): float
    {
        return $this->deadline - microtime(true);
    }

    public function run(): array
    {
        if (!self::lock()) {
            return ['Another run is in progress; skipped.'];
        }
        $this->step('treasurer', fn() => 'imported ' . Treasurer::import(20000) . ' rows', 10);
        $this->daily('treasurer_watch', fn() => Treasurer::watch());
        $this->pagedPass(DetroitBlight::JOB, [DetroitBlight::class, 'page'], [DetroitBlight::class, 'finishPass']);
        foreach (array_keys(DetroitSignals::sources()) as $key) {
            $this->pagedPass($key, fn(int $o) => DetroitSignals::page($key, $o), fn(string $t) => DetroitSignals::finishPass($key, $t), DetroitSignals::PAGE_SIZE);
        }
        $this->loop('detroit_parcels', fn() => DetroitParcels::batch(150), 6);
        $this->loop(MacombParcels::JOB, fn() => MacombParcels::batch(150), 6);
        // Scores depend on today's date (tax foreclosure date passed? ticket in last 12 months?),
        // so everything is rescored once a month; April 1 turns "foreclosure pending" (+45) into +25.
        if (Db::setting('rescore_month') !== date('Y-m')) {
            Db::run('UPDATE properties SET needs_score = 1');
            Db::setSetting('rescore_month', date('Y-m'));
        }
        $this->loop('scoring', fn() => Scoring::rescore(5000), 2);
        $this->every('list_counts', 3600, fn() => 'counted ' . Lists::recount() . ' lists', 15);
        $this->autoTrace();
        if (Env::get('DEALMACHINE_API_KEY', '') !== '') {
            // leads traced before DealMachine's street address (with St/Ave/Rd) was stored; repeat lookups are free
            $this->loop('dm_address_backfill', fn() => SkipTrace::backfillAddresses(250), 20);
        }
        $this->daily('dnc_recheck', fn() => 'checked ' . SkipTrace::recheckDnc(1000) . ' numbers', 20);
        Db::val("SELECT RELEASE_LOCK('leads_jobs')");
        return $this->log;
    }

    /**
     * Work still waiting, for "Run all jobs until done": data passes not finished today, Detroit parcels
     * never synced, leads waiting for a score, and list counts older than an hour. Empty = all caught up.
     *
     * @return array<string,string> job => what is left
     */
    public static function pending(): array
    {
        $left = [];
        $states = [];
        foreach (Db::all('SELECT * FROM job_state') as $s) {
            $states[$s['job']] = $s;
        }
        $fresh = static fn(?array $s, int $secs): bool => $s && $s['last_success_at'] && strtotime($s['last_success_at'] . ' UTC') > time() - $secs;
        foreach (array_merge([DetroitBlight::JOB], array_keys(DetroitSignals::sources())) as $job) {
            $s = $states[$job] ?? null;
            if (($s['cursor_value'] ?? null) !== null) {
                $left[$job] = 'in progress';
            } elseif (!$fresh($s, 20 * 3600)) {
                $left[$job] = 'not run today';
            }
        }
        $unsynced = (int)Db::val("SELECT COUNT(*) FROM properties WHERE municipality = 'DETROIT' AND parcel_synced_at IS NULL");
        if ($unsynced > 0) {
            $left['detroit_parcels'] = number_format($unsynced) . ' parcels without owner data';
        }
        $unsynced = (int)Db::val("SELECT COUNT(*) FROM properties WHERE county = 'MACOMB' AND parcel_synced_at IS NULL");
        if ($unsynced > 0) {
            $left[MacombParcels::JOB] = number_format($unsynced) . ' Macomb parcels without owner data';
        }
        $unscored = (int)Db::val('SELECT COUNT(*) FROM properties WHERE needs_score = 1');
        if ($unscored > 0) {
            $left['scoring'] = number_format($unscored) . ' leads to score';
        }
        $noAddr = Env::get('DEALMACHINE_API_KEY', '') !== '' ? (int)Db::val('SELECT COUNT(*) FROM properties WHERE dm_matched = 1 AND dm_address IS NULL') : 0;
        if ($noAddr > 0) {
            $left['dm_address_backfill'] = number_format($noAddr) . ' traced leads without the full street address';
        }
        if (!$fresh($states['list_counts'] ?? null, 3600)) {
            $left['list_counts'] = 'counts older than an hour';
        }
        return $left;
    }

    private function state(string $job): array
    {
        $s = Db::one('SELECT * FROM job_state WHERE job = ?', [$job]);
        if (!$s) {
            Db::run("INSERT INTO job_state (job, status) VALUES (?, 'idle')", [$job]);
            $s = Db::one('SELECT * FROM job_state WHERE job = ?', [$job]);
        }
        return $s;
    }

    private function save(string $job, array $fields): void
    {
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $job;
        Db::run('UPDATE job_state SET ' . implode(', ', $sets) . ' WHERE job = ?', $vals);
    }

    /** Runs $fn once if at least $minSeconds remain. */
    private function step(string $job, callable $fn, int $minSeconds = 5): void
    {
        if ($this->left() < $minSeconds) {
            return;
        }
        $this->state($job);
        $now = gmdate('Y-m-d H:i:s');
        $this->save($job, ['status' => 'running', 'last_started_at' => $now]);
        try {
            $msg = (string)$fn();
            $this->save($job, ['status' => 'ok', 'last_finished_at' => gmdate('Y-m-d H:i:s'), 'last_success_at' => gmdate('Y-m-d H:i:s'), 'message' => mb_substr($msg, 0, 500)]);
            $this->log[] = "$job: $msg";
        } catch (\Throwable $e) {
            $this->save($job, ['status' => 'error', 'last_finished_at' => gmdate('Y-m-d H:i:s'), 'message' => mb_substr($e->getMessage(), 0, 500)]);
            $this->log[] = "$job ERROR: " . $e->getMessage();
            Log::error("$job: " . $e->getMessage());
        }
    }

    private function daily(string $job, callable $fn, int $minSeconds = 5): void
    {
        $this->every($job, 20 * 3600, $fn, $minSeconds);
    }

    /** Runs $fn if its last success is older than $seconds. */
    private function every(string $job, int $seconds, callable $fn, int $minSeconds = 5): void
    {
        $s = $this->state($job);
        if ($s['last_success_at'] && strtotime($s['last_success_at'] . ' UTC') > time() - $seconds) {
            return;
        }
        $this->step($job, $fn, $minSeconds);
    }

    /** Repeats a batch function until it returns 0 or time runs short. */
    private function loop(string $job, callable $batch, int $minSeconds): void
    {
        $total = 0;
        $this->step($job, function () use ($batch, $minSeconds, &$total) {
            do {
                $n = (int)$batch();
                $total += $n;
            } while ($n > 0 && $this->left() > $minSeconds);
            return "processed $total";
        }, $minSeconds);
        Db::run('UPDATE job_state SET rows_last_run = ? WHERE job = ?', [$total, $job]);
    }

    /**
     * A full paged pass over an ArcGIS source, started once a day and resumed across runs.
     * $page(int $offset): [rows, finished]; $finish(string $passStartedUtc): rows deactivated.
     * Each run spends at most $sliceSeconds here so one big pass can't starve the other jobs.
     */
    private function pagedPass(string $job, callable $page, callable $finish, int $pageSize = 1000, int $sliceSeconds = 60): void
    {
        $s = $this->state($job);
        $cursor = $s['cursor_value'] ? json_decode((string)$s['cursor_value'], true) : null;
        if (!$cursor) {
            if ($s['last_success_at'] && strtotime($s['last_success_at'] . ' UTC') > time() - 20 * 3600) {
                return; // pass done today
            }
            $cursor = ['offset' => 0, 'started' => gmdate('Y-m-d H:i:s')];
        }
        $this->step($job, function () use (&$cursor, $job, $page, $finish, $pageSize, $sliceSeconds) {
            $rows = 0;
            $finished = false;
            $stop = microtime(true) + $sliceSeconds;
            while ($this->left() > 12 && microtime(true) < $stop) {
                [$n, $finished] = $page((int)$cursor['offset']);
                $rows += $n;
                $cursor['offset'] += $pageSize;
                $this->save($job, ['cursor_value' => json_encode($cursor)]);
                if ($finished) {
                    break;
                }
            }
            if ($finished) {
                $closed = $finish($cursor['started']);
                $this->save($job, ['cursor_value' => null]);
                return "pass complete: $rows parcels this run, $closed closed";
            }
            // not finished: keep status ok but do not mark the daily pass done
            return "in progress: offset {$cursor['offset']} ($rows parcels this run)";
        }, 15);
        if ($cursor && ($this->state($job)['cursor_value'] ?? null) !== null) {
            // pass still running: undo last_success so the daily check does not skip the remainder
            Db::run('UPDATE job_state SET last_success_at = NULL WHERE job = ?', [$job]);
        }
    }

    private function autoTrace(): void
    {
        if (Db::setting('auto_trace', '1') !== '1' || Env::get('DEALMACHINE_API_KEY', '') === '') {
            return;
        }
        $min = (int)Db::setting('trace_min_score', '50');
        $batch = max(1, min(250, (int)Db::setting('auto_trace_batch', '250')));
        $this->step('auto_trace', function () use ($min, $batch) {
            $msgs = [];
            while ($this->left() > 25) {
                $ids = array_column(Db::all(
                    'SELECT parcel_id FROM properties WHERE is_excluded = 0 AND score >= ? AND traced_at IS NULL AND exclusive_client_id IS NULL
                     AND status NOT IN (\'dead\', \'closed\')
                     AND (municipality <> \'DETROIT\' OR parcel_synced_at IS NOT NULL) ORDER BY score DESC LIMIT ' . $batch,
                    [$min]
                ), 'parcel_id');
                if (!$ids) {
                    break;
                }
                $r = SkipTrace::trace($ids, null, null, 'auto');
                $msgs[] = $r['message'];
                if ($r['submitted'] === 0) {
                    break;
                }
            }
            return $msgs ? implode(' | ', $msgs) : 'nothing to trace';
        }, 25);
    }
}
