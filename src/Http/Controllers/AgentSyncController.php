<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Rconfig\VectorServer\Jobs\UpdateAgentDevicesStatusJob;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;

class AgentSyncController extends Controller
{
    protected $agent;

    public function status()
    {
        return response()->json(['status' => 'Agent Sync API is working']);
    }

    public function sync()
    {
        $this->agent = Agent::where('id', app('agent_id'))->first();

        if (!$this->agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        // Check if agent is admin disabled
        if (!$this->agent->is_admin_enabled) {
            return response()->json(['error' => 'Agent is disabled by admin'], 403);
        }

        $wasDown = $this->agent->status == 2; // Track if agent was previously down

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($wasDown) {
            $this->agent->last_check_in_at = now()->format('Y-m-d H:i:s');
            $this->agent->missed_checkins = 0;
            $this->agent->next_scheduled_checkin_at = now()->addSeconds($this->agent->checkin_interval)->format('Y-m-d H:i:s');
            $this->agent->status = 1; // Set to healthy
            $this->agent->save();

            // If agent was down and now recovered, trigger device status recovery
            if ($wasDown) {
                $this->triggerDeviceRecovery();
            }

            $this->log_checkin($wasDown);
        });

        // Hide sensitive data before returning
        $response = $this->agent->makeHidden(['srcip', 'api_token']);

        return response()->json($response);
    }

    /**
     * Trigger device status recovery when agent comes back online
     */
    private function triggerDeviceRecovery()
    {
        if ($this->agent->devices()->count() > 0) {
            // Dispatch job to recover device statuses
            dispatch(new UpdateAgentDevicesStatusJob(
                $this->agent->id,
                1, // Healthy status
                'Agent has recovered'
            ))->onQueue('rConfigDefault');
        }
    }

    /**
     * Log the check-in event
     */
    private function log_checkin($wasRecovering = false)
    {
        $msg = $wasRecovering
            ? "Agent {$this->agent->name} has RECOVERED and checked in"
            : "Agent {$this->agent->name} has checked in";

        $logLevel = $wasRecovering ? 'INFO' : 'DEBUG';

        AgentLog::insert([
            'agent_id' => $this->agent->id,
            'executed_at' => now(),
            'log_level' => $logLevel,
            'message' => $msg,
            'operation' => 'agent_checkin',
            'context_data' => json_encode([
                'was_recovering' => $wasRecovering,
                'missed_checkins_reset' => true
            ]),
            'entity_type' => 'AgentSyncController',
            'entity_id' => null,
            'correlation_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
