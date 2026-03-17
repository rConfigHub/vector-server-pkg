<?php

namespace Rconfig\VectorServer\Services\AgentTaskRuns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rconfig\VectorServer\Models\AgentTaskRunTracker;
use Rconfig\VectorServer\Models\AgentTaskRunUnit;

class RunTrackerService
{
    public function registerExpectedUnit(array $payload): void
    {
        if (! $this->tablesAvailable()) {
            return;
        }

        if (empty($payload['task_run_id']) || empty($payload['task_report_id'])) {
            return;
        }

        $tracker = AgentTaskRunTracker::firstOrCreate(
            ['run_id' => $payload['task_run_id']],
            [
                'report_id' => $payload['task_report_id'],
                'task_id' => $payload['task_id'] ?? null,
                'status' => AgentTaskRunTracker::STATUS_RUNNING,
                'started_at' => now(),
            ]
        );

        AgentTaskRunUnit::create([
            'run_id' => $payload['task_run_id'],
            'report_id' => $payload['task_report_id'],
            'task_id' => $payload['task_id'] ?? null,
            'device_id' => $payload['device_id'],
            'agent_id' => $payload['agent_id'] ?? null,
            'command' => $payload['command'] ?? null,
            'queue_ulid' => $payload['queue_ulid'] ?? null,
            'status' => AgentTaskRunUnit::STATUS_PENDING,
            'started_at' => now(),
        ]);

        $tracker->increment('expected_total');
        $tracker->increment('pending_total');
    }

    public function markUnitSuccessByUlid(string $queueUlid): void
    {
        if (! $this->tablesAvailable()) {
            return;
        }

        $this->transitionByUlid($queueUlid, AgentTaskRunUnit::STATUS_SUCCESS, 'success_total');
    }

    public function markUnitFailedByUlid(string $queueUlid, ?string $error = null): void
    {
        if (! $this->tablesAvailable()) {
            return;
        }

        $this->transitionByUlid($queueUlid, AgentTaskRunUnit::STATUS_FAILED, 'failed_total', $error);
    }

    public function markTimedOutUnits(string $runId, int $timeoutSeconds): int
    {
        if (! $this->tablesAvailable()) {
            return 0;
        }

        if ($timeoutSeconds <= 0) {
            return 0;
        }

        $threshold = Carbon::now()->subSeconds($timeoutSeconds);

        $units = AgentTaskRunUnit::where('run_id', $runId)
            ->where('status', AgentTaskRunUnit::STATUS_PENDING)
            ->where('started_at', '<=', $threshold)
            ->get();

        if ($units->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($units as $unit) {
            $unit->status = AgentTaskRunUnit::STATUS_TIMEOUT;
            $unit->last_error = 'Timed out waiting for agent completion callback.';
            $unit->ended_at = now();
            $unit->save();
            $count++;
        }

        AgentTaskRunTracker::where('run_id', $runId)->update([
            'pending_total' => DB::raw('GREATEST(pending_total - ' . $count . ', 0)'),
            'timeout_total' => DB::raw('timeout_total + ' . $count),
            'updated_at' => now(),
        ]);

        return $count;
    }

    public function getPendingCount(string $runId): int
    {
        if (! $this->tablesAvailable()) {
            return 0;
        }

        return (int) AgentTaskRunTracker::where('run_id', $runId)->value('pending_total');
    }

    public function finalizeRun(string $runId): void
    {
        if (! $this->tablesAvailable()) {
            return;
        }

        $tracker = AgentTaskRunTracker::where('run_id', $runId)->first();

        if (! $tracker) {
            return;
        }

        if ($tracker->pending_total > 0) {
            $tracker->status = AgentTaskRunTracker::STATUS_PARTIAL_TIMEOUT;
        } elseif ($tracker->failed_total > 0 || $tracker->timeout_total > 0) {
            $tracker->status = AgentTaskRunTracker::STATUS_COMPLETED_WITH_FAILURES;
        } else {
            $tracker->status = AgentTaskRunTracker::STATUS_COMPLETED;
        }

        $tracker->finalized_at = now();
        $tracker->save();
    }

    private function transitionByUlid(string $queueUlid, int $toStatus, string $counterField, ?string $error = null): void
    {
        $unit = AgentTaskRunUnit::where('queue_ulid', $queueUlid)->first();

        if (! $unit || $unit->status !== AgentTaskRunUnit::STATUS_PENDING) {
            return;
        }

        $unit->status = $toStatus;
        $unit->last_error = $error;
        $unit->ended_at = now();
        $unit->save();

        AgentTaskRunTracker::where('run_id', $unit->run_id)->update([
            'pending_total' => DB::raw('GREATEST(pending_total - 1, 0)'),
            $counterField => DB::raw($counterField . ' + 1'),
            'updated_at' => now(),
        ]);
    }

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('agent_task_run_trackers') && Schema::hasTable('agent_task_run_units');
    }
}
