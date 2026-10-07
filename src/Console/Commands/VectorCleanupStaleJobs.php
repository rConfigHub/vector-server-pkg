<?php

namespace Rconfig\VectorServer\Console\Commands;

use App\Models\Device;
use App\Services\Notifications\AgentDeviceFailureNotifier;
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
            $notifyDeviceId = null;
            $notifyRunId = null;

            DB::transaction(function () use ($job, &$notifyDeviceId, &$notifyRunId) {
                $fresh = AgentQueue::lockForUpdate()->find($job->id);

                if (! $fresh || $fresh->processed || $fresh->retry_failed) {
                    return;
                }

                if ($fresh->retry_attempt === 0) {
                    $fresh->retry_failed = 1;
                    $fresh->save();

                    $transitioned = (new RunTrackerService)->markUnitFailedByUlid($fresh->ulid, 'Agent retries exhausted - job timed out.');
                    Device::where('id', $fresh->device_id)->update(['status' => 0]);

                    if ($transitioned) {
                        $notifyDeviceId = (int) $fresh->device_id;
                        $notifyRunId = $fresh->task_run_id;
                    }

                    $this->warn("Job {$fresh->ulid} permanently failed (retries exhausted).");
                } else {
                    $fresh->retry_attempt--;
                    $fresh->save();

                    $this->line("Job {$fresh->ulid} retry decremented to {$fresh->retry_attempt}.");
                }
            });

            // Fire the failure notification outside the transaction so mail dispatch
            // does not hold the row lock. Only fires on the transition into FAILED.
            if ($notifyDeviceId !== null) {
                (new AgentDeviceFailureNotifier)->notifyDeviceFailure($notifyDeviceId, 'Agent retries exhausted - job timed out.', $notifyRunId);
            }
        }

        return 0;
    }
}
