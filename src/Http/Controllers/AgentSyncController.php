<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
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
        $this->agent = $this->agent->makeHidden(['srcip', 'api_token']);

        if (!$this->agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $this->agent->last_check_in_at = now()->format('Y-m-d H:i:s');
        $this->agent->missed_checkins = 0;
        $this->agent->next_scheduled_checkin_at = now()->addSeconds($this->agent->checkin_interval)->format('Y-m-d H:i:s');
        $this->agent->status = 1;
        $this->agent->save();

        $this->log_checkin();

        return response()->json($this->agent);
    }

    private function log_checkin()
    {
        $msg = "Agent {$this->agent->name} has checked in";
        AgentLog::insert([
            'agent_id' => $this->agent->id,
            'executed_at' => now(),
            'log_level' => 'WARN',
            'message' => $msg,
            'operation' => 'agent_checkin',
            'context_data' => null,
            'entity_type' => 'AgentSyncController',
            'entity_id' => null,
            'correlation_id' =>  null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
