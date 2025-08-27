<?php

namespace Rconfig\VectorServer\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rconfig\VectorServer\Jobs\UpdateAgentDevicesStatusJob;
use Rconfig\VectorServer\Models\Agent;

class VectorMonitorAgentCheckIns extends Command
{
    protected $signature = 'vector:agent-checkins';
    protected $description = 'Monitor agents to track missed check-ins';

    public function handle()
    {
        $this->info('Starting agent check-in monitoring...');

        // Step 1: Initialize any agents that need scheduling setup
        $this->initializeScheduledCheckIns();

        // Step 2: Process overdue agents and collect status changes
        $changedAgents = $this->processOverdueAgents();

        // Step 3: Update device statuses only for agents that changed
        $this->updateDeviceStatuses($changedAgents);

        // Step 4: Handle disabled agents
        $this->handleDisabledAgents();

        $this->info('Agent monitoring completed.');
        return 0;
    }

    /**
     * Initialize scheduling for agents that need it
     */
    protected function initializeScheduledCheckIns()
    {
        // Agents that never checked in
        $neverCheckedIn = Agent::activeAgents()
            ->whereNull('last_check_in_at')
            ->whereNull('next_scheduled_checkin_at')
            ->get();

        foreach ($neverCheckedIn as $agent) {
            $agent->next_scheduled_checkin_at = Carbon::parse($agent->created_at)->addSeconds($agent->checkin_interval);
            $agent->save();
            $this->line("Initialized schedule for agent: {$agent->name}");
        }

        // Agents with check-in history but no next scheduled time
        $needsScheduling = Agent::activeAgents()
            ->whereNotNull('last_check_in_at')
            ->whereNull('next_scheduled_checkin_at')
            ->get();

        foreach ($needsScheduling as $agent) {
            $agent->scheduleNextCheckIn(true); // Use retry interval
            $this->line("Rescheduled agent: {$agent->name}");
        }
    }

    /**
     * Process overdue agents and return those that changed status
     */
    protected function processOverdueAgents()
    {
        $changedAgents = [];

        $overdueAgents = Agent::activeAgents()
            ->overdue()
            ->get();

        foreach ($overdueAgents as $agent) {
            $this->processOverdueAgent($agent, $changedAgents);
        }

        if (count($changedAgents) > 0) {
            $this->warn('Agents marked as down: ' . implode(', ', array_column($changedAgents, 'name')));
        }

        return $changedAgents;
    }

    /**
     * Process a single overdue agent
     */
    protected function processOverdueAgent($agent, &$changedAgents)
    {
        DB::transaction(function () use ($agent, &$changedAgents) {
            $originalStatus = $agent->status;
            $originalMissedCheckins = $agent->missed_checkins;

            // Increment missed check-ins
            $agent->missed_checkins++;

            $this->line("Processing agent {$agent->name}: status={$originalStatus}, missed_checkins={$originalMissedCheckins}->{$agent->missed_checkins}, max={$agent->max_missed_checkins}");

            // Check if agent should be marked as down
            if ($agent->shouldBeMarkedDown()) {
                if ($agent->markAsDown("Exceeded {$agent->max_missed_checkins} missed check-ins")) {
                    $changedAgents[] = $agent;
                    $this->warn("Agent {$agent->name} status changed from {$originalStatus} to {$agent->status} - will update devices");
                } else {
                    $this->line("Agent {$agent->name} already down - no device update needed");
                }
            }

            // Schedule next check using retry interval for failed agents
            $agent->scheduleNextCheckIn(true);
        });
    }

    /**
     * Update device statuses for agents that changed status
     */
    protected function updateDeviceStatuses($changedAgents)
    {
        if (empty($changedAgents)) {
            $this->line("No agents changed status - skipping device updates");
            return;
        }

        $this->info("Processing device updates for " . count($changedAgents) . " agents");

        foreach ($changedAgents as $agent) {
            $deviceCount = $agent->devices()->count();
            $this->line("Agent {$agent->name} has {$deviceCount} devices");

            if ($deviceCount > 0) {
                $this->info("Dispatching UpdateAgentDevicesStatusJob for agent {$agent->name}");
                dispatch(new UpdateAgentDevicesStatusJob(
                    $agent->id,
                    300,
                    'Agent is down'
                ))->onQueue('rConfigDefault');
            } else {
                $this->line("Agent {$agent->name} has no devices - skipping device update");
            }
        }
    }

    /**
     * Handle agents that are administratively disabled
     */
    protected function handleDisabledAgents()
    {
        // Find agents that were just disabled (status changed to disabled recently)
        $recentlyDisabled = Agent::where('is_admin_enabled', 0)
            ->where('status', Agent::STATUS_DISABLED)
            ->where('id', '>', 1)
            ->whereHas('devices')
            ->where('updated_at', '>=', Carbon::now()->subMinutes(2))
            ->get();

        foreach ($recentlyDisabled as $agent) {
            $this->info("Updating device statuses for recently disabled agent: {$agent->name}");
            dispatch(new UpdateAgentDevicesStatusJob(
                $agent->id,
                301,
                'Agent is administratively disabled'
            ))->onQueue('rConfigDefault');
        }

        // Also handle agents that are disabled but don't have the disabled status yet
        // (for backward compatibility with existing disabled agents)
        $legacyDisabled = Agent::where('is_admin_enabled', 0)
            ->where('status', '!=', Agent::STATUS_DISABLED)
            ->where('id', '>', 1)
            ->get();

        foreach ($legacyDisabled as $agent) {
            $this->line("Updating legacy disabled agent status: {$agent->name}");
            $agent->status = Agent::STATUS_DISABLED;
            $agent->save();
        }
    }
}
