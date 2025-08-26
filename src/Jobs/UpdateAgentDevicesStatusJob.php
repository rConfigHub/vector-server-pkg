<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;

class UpdateAgentDevicesStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $agentId;
    public $newStatus;
    public $reason;

    /**
     * Create a new job instance.
     */
    public function __construct(int $agentId, int $newStatus, string $reason)
    {
        $this->agentId = $agentId;
        $this->newStatus = $newStatus;
        $this->reason = $reason;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $agent = Agent::with('devices')->find($this->agentId);

        if (!$agent || $agent->devices->isEmpty()) {
            return;
        }

        // Update all devices belonging to this agent
        $updatedCount = $agent->devices()->update(['status' => $this->newStatus]);

        // Log the device status change
        $this->logDeviceStatusChange($agent, $updatedCount);
    }

    /**
     * Log device status changes
     */
    protected function logDeviceStatusChange(Agent $agent, int $deviceCount): void
    {
        $statusText = $this->getStatusText($this->newStatus);

        $msg = "Updated {$deviceCount} device(s) for agent {$agent->name} to status {$statusText}: {$this->reason}";
        \Log::info('INFO: ' . $msg);

        AgentLog::create([
            'agent_id' => $agent->id,
            'executed_at' => now(),
            'log_level' => 'INFO',
            'message' => $msg,
            'operation' => 'device_status_update',
            'context_data' => json_encode([
                'device_count' => $deviceCount,
                'new_status' => $this->newStatus,
                'reason' => $this->reason,
                'job_class' => self::class
            ]),
            'entity_type' => 'UpdateAgentDevicesStatusJob',
            'entity_id' => $agent->id,
            'correlation_id' => null,
        ]);
    }

    /**
     * Get human-readable status text
     */
    protected function getStatusText(int $status): string
    {
        return match ($status) {
            300 => 'down (agent down)',
            301 => 'disabled (agent disabled)',
            default => "status {$status}"
        };
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['agent:' . $this->agentId, 'device-status-update', 'status:' . $this->newStatus];
    }
}
