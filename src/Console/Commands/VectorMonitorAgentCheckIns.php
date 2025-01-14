<?php

namespace Rconfig\VectorServer\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;

class VectorMonitorAgentCheckIns extends Command
{
    protected $signature = 'vector:agent-checkins';
    protected $description = 'Monitor agents to track missed check-ins';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // status 1 = active, status 2 = down, 0 = disabled
        $now = Carbon::now('UTC'); // Assuming your DB is in UTC

        // Update agents with null `next_scheduled_checkin_at` by adding `retry_interval` to `last_check_in_at`
        Agent::whereNull('next_scheduled_checkin_at')
            ->whereNotNull('last_check_in_at')
            ->get()
            ->each(function ($agent) {
                $agent->next_scheduled_checkin_at = Carbon::parse($agent->last_check_in_at)->addSeconds($agent->retry_interval);
                $agent->save();
            });


        // Get all agents that have surpassed `next_scheduled_checkin_at`
        $agents = Agent::where('next_scheduled_checkin_at', '<', $now)
            ->where('status', '=', 1) // Only active agents
            ->where('id', '>', 1) // Only agents with an ID greater than 1 (not the default agent)
            ->get();


        foreach ($agents as $agent) {
            // Increment the missed check-ins
            $agent->missed_checkins++;

            // If they exceed the allowed number of missed check-ins, trigger alert
            if ($agent->missed_checkins >= $agent->max_missed_checkins) {
                $this->alertMissedCheckIns($agent);
            }

            // Update the agent's status if necessary (for example, blocking the agent)
            if ($agent->missed_checkins >= $agent->max_missed_checkins) {

                $agent->status = 2; // Blocked or Alert status
            }

            // Save the updated agent
            $agent->save();
        }

        return 0;
    }

    protected function alertMissedCheckIns($agent)
    {
        // Logic for sending alerts, notifications, or logging
        // This could send an email, SMS, or trigger another action
        $msg = "Agent {$agent->name} has missed check-ins: " . $agent->missed_checkins;
        \Log::info('WARN: ' . $msg);
        AgentLog::insert([
            'agent_id' => $agent->id,
            'executed_at' => now(),
            'log_level' => 'WARN',
            'message' => $msg,
            'operation' => 'agent_checkin',
            'context_data' => null,
            'entity_type' => 'VectorMonitorAgentCheckIns',
            'entity_id' => null,
            'correlation_id' =>  null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
