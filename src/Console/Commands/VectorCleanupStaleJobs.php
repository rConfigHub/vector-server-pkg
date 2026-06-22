<?php

namespace Rconfig\VectorServer\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rconfig\VectorServer\Models\AgentQueue;
use Rconfig\VectorServer\Services\AgentTaskRuns\RunTrackerService;

class VectorCleanupStaleJobs extends Command
{
    protected $signature = 'vector:cleanup-stale-jobs';
    protected $description = 'Mark stale unprocessed agent queue jobs as failed or decrement their retry count';

    public function handle()
    {
        $timeoutMinutes = config('vector-server.stale_job_timeout_minutes', 10);

        $staleJobs = AgentQueue::where('processed', 0)
            ->where('retry_failed', 0)
            ->where('updated_at', '<', now()->subMinutes($timeoutMinutes))
            ->get();

        if ($staleJobs->isEmpty()) {
            return 0;
        }

        $this->line("Found {$staleJobs->count()} stale job(s) older than {$timeoutMinutes} minutes.");

        foreach ($staleJobs as $job) {
            DB::transaction(function () use ($job) {
                $fresh = AgentQueue::lockForUpdate()->find($job->id);

                if (!$fresh || $fresh->processed || $fresh->retry_failed) {
                    return;
                }

                if ($fresh->retry_attempt === 0) {
                    $fresh->retry_failed = 1;
                    $fresh->save();

                    (new RunTrackerService)->markUnitFailedByUlid($fresh->ulid, 'Agent retries exhausted - job timed out.');
                    Device::where('id', $fresh->device_id)->update(['status' => 0]);

                    $this->warn("Job {$fresh->ulid} permanently failed (retries exhausted).");
                } else {
                    $fresh->retry_attempt--;
                    $fresh->save();

                    $this->line("Job {$fresh->ulid} retry decremented to {$fresh->retry_attempt}.");
                }
            });
        }

        return 0;
    }
}
