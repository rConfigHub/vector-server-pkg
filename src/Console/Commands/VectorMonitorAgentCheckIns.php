<?php

namespace Rconfig\VectorServer\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Rconfig\VectorServer\Jobs\UpdateAgentDevicesStatusJob;
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
        $now = Carbon::now(); // Use Laravel's configured timezone

        // Step 1: Initialize next_scheduled_checkin_at for agents that need it
        $this->initializeScheduledCheckIns($now);

        // Step 2: Process overdue agents
        $this->processOverdueAgents($now);

        // Step 3: Update device statuses based on agent states
        $this->updateDeviceStatuses();

        return 0;
    }

    /**
     * Initialize next_scheduled_checkin_at for agents that don't have it set
     */
    protected function initializeScheduledCheckIns($now)
    {
        // Handle agents that have never checked in - set initial next check-in time
        Agent::whereNull('last_check_in_at')
            ->whereNull('next_scheduled_checkin_at')
            ->where('is_admin_enabled', '=', 1)
            ->where('id', '>', 1)
            ->get()
            ->each(function ($agent) {
                // Set initial next check-in based on checkin_interval from creation time
                $agent->next_scheduled_checkin_at = Carbon::parse($agent->created_at)->addSeconds($agent->checkin_interval);
                $agent->save();
            });

        // Update agents with null `next_scheduled_checkin_at` by adding `retry_interval` to `last_check_in_at`
        Agent::whereNull('next_scheduled_checkin_at')
            ->whereNotNull('last_check_in_at')
            ->where('is_admin_enabled', '=', 1)
            ->where('id', '>', 1)
            ->get()
            ->each(function ($agent) {
                $agent->next_scheduled_checkin_at = Carbon::parse($agent->last_check_in_at)->addSeconds($agent->retry_interval);
                $agent->save();
            });
    }

    /**
     * Process agents that have overdue check-ins
     */
    protected function processOverdueAgents($now)
    {
        // Get all agents that have surpassed `next_scheduled_checkin_at`
        $agents = Agent::where('next_scheduled_checkin_at', '<', $now)
            ->where('is_admin_enabled', '=', 1) // Only admin enabled agents
            ->where('id', '>', 1) // Only agents with an ID greater than 1 (not the default agent)
            ->get();

        foreach ($agents as $agent) {
            $this->processOverdueAgent($agent);
        }
    }

    /**
     * Process a single overdue agent
     */
    protected function processOverdueAgent($agent)
    {
        // Increment the missed check-ins
        $agent->missed_checkins++;

        // If they exceed the allowed number of missed check-ins, trigger alert and block
        if ($agent->missed_checkins >= $agent->max_missed_checkins) {
            $this->alertMissedCheckIns($agent);
            $agent->status = 2; // Blocked or Alert status
        }

        // Save the updated agent
        $agent->save();
    }

    /**
     * Update device statuses based on agent states by dispatching jobs
     */
    protected function updateDeviceStatuses()
    {
        // Dispatch jobs for agents that are down (status = 2)
        $downAgentIds = Agent::where('status', 2)
            ->where('id', '>', 1)
            ->whereHas('devices') // Only agents that have devices
            ->pluck('id');

        foreach ($downAgentIds as $agentId) {
            UpdateAgentDevicesStatusJob::dispatch($agentId, 300, 'Agent is down');
        }

        // Dispatch jobs for agents that are disabled (is_admin_enabled = 0)
        $disabledAgentIds = Agent::where('is_admin_enabled', 0)
            ->where('id', '>', 1)
            ->whereHas('devices') // Only agents that have devices
            ->pluck('id');

        foreach ($disabledAgentIds as $agentId) {
            UpdateAgentDevicesStatusJob::dispatch($agentId, 301, 'Agent is disabled');
        }
    }

    /**
     * Alert for missed check-ins
     */
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
