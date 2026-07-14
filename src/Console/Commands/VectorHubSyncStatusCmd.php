<?php

namespace Rconfig\VectorServer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Services\VectorHubClient;

class VectorHubSyncStatusCmd extends Command
{
    protected $signature = 'vector:hub-sync-status';

    protected $description = 'Persist Vector Hub live-channel status (connected, RTT, last seen) onto agents';

    public function handle(VectorHubClient $hub)
    {
        if (! $hub->isConfigured()) {
            return 0;
        }

        $reported = $hub->agents();
        if ($reported === null) {
            // Hub unreachable is not proof agents dropped — keep the last
            // observed state rather than flapping everyone to disconnected.
            $this->warn('Vector Hub unreachable; live-channel status left unchanged.');

            return 1;
        }

        $connectedIds = [];
        foreach ($reported as $status) {
            $agentId = (int) ($status['agent_id'] ?? 0);
            if ($agentId < 1) {
                continue;
            }
            $connectedIds[] = $agentId;

            Agent::where('id', $agentId)->update([
                'live_channel_connected' => (bool) ($status['live'] ?? false),
                'live_channel_rtt_ms' => $status['rtt_ms'] ?? null,
                'live_channel_last_seen_at' => isset($status['last_seen'])
                    ? Carbon::parse($status['last_seen'])
                    : now(),
            ]);
        }

        // The hub answered authoritatively: anything it did not report is
        // no longer connected.
        Agent::where('live_channel_connected', true)
            ->whereNotIn('id', $connectedIds)
            ->update(['live_channel_connected' => false, 'live_channel_rtt_ms' => null]);

        $this->line('Synced live-channel status for '.count($connectedIds).' connected agent(s).');

        return 0;
    }
}
