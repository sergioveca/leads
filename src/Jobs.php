<?php
declare(strict_types=1);

namespace Leads;

use Leads\Sources\DetroitBlight;
use Leads\Sources\DetroitParcels;
use Leads\Sources\Treasurer;

/**
 * Scheduled work, run in time-boxed slices so it fits shared-hosting limits.
 * Every job is resumable (state in job_state), so a run can stop anywhere and continue next time.
 *
 * Order per run: Treasurer CSV import -> Treasurer page watch (daily) -> blight pass (daily, paged)
 * -> Detroit parcel sync (owner/mailing) -> scoring -> auto skip trace -> DNC re-check (daily).
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
        $this->blight();
        $this->loop('detroit_parcels', fn() => DetroitParcels::batch(150), 6);
        // Scores depend on today's date (tax foreclosure date passed? ticket in last 12 months?),
        // so everything is rescored once a month; April 1 turns "foreclosure pending" (+45) into +25.
        if (Db::setting('rescore_month') !== date('Y-m')) {
            Db::run('UPDATE properties SET needs_score = 1');
            Db::setSetting('rescore_month', date('Y-m'));
        }
        $this->loop('scoring', fn() => Scoring::rescore(5000), 2);
        $this->autoTrace();
        $this->daily('dnc_recheck', fn() => 'checked ' . SkipTrace::recheckDnc(1000) . ' numbers', 20);
        Db::val("SELECT RELEASE_LOCK('leads_jobs')");
        return $this->log;
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
        $s = $this->state($job);
        if ($s['last_success_at'] && strtotime($s['last_success_at'] . ' UTC') > time() - 20 * 3600) {
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

    /** Blight: a full paged pass starts once a day and resumes across runs. */
    private function blight(): void
    {
        $job = DetroitBlight::JOB;
        $s = $this->state($job);
        $cursor = $s['cursor_value'] ? json_decode((string)$s['cursor_value'], true) : null;
        if (!$cursor) {
            if ($s['last_success_at'] && strtotime($s['last_success_at'] . ' UTC') > time() - 20 * 3600) {
                return; // pass done today
            }
            $cursor = ['offset' => 0, 'started' => gmdate('Y-m-d H:i:s')];
        }
        $this->step($job, function () use (&$cursor, $job) {
            $rows = 0;
            $finished = false;
            while ($this->left() > 12) {
                [$n, $finished] = DetroitBlight::page((int)$cursor['offset']);
                $rows += $n;
                $cursor['offset'] += 1000;
                $this->save($job, ['cursor_value' => json_encode($cursor)]);
                if ($finished) {
                    break;
                }
            }
            if ($finished) {
                $closed = DetroitBlight::finishPass($cursor['started']);
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
