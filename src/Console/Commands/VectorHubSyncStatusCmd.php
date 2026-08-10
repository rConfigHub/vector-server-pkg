<?php

namespace Rconfig\VectorServer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Rconfig\VectorServer\Jobs\SyncHubCertsJob;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\VectorHubInstall;
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
            // A common, recoverable cause is the Laravel client certs going
            // missing (the mTLS handshake fails and every call reads as down),
            // so try to self-heal them here before giving up for this tick.
            $this->selfHealClientCerts($hub);

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

        $this->line('Synced live-channel status for ' . count($connectedIds) . ' connected agent(s).');

        return 0;
    }

    /**
     * If the hub is installed but the Laravel client certs are missing, queue
     * a re-sync (root Horizon worker re-copies them from the hub's tls dir).
     * Rate-limited to one attempt per window so a persistently-missing source
     * can't spawn a fresh job every scheduler tick.
     */
    private function selfHealClientCerts(VectorHubClient $hub): void
    {
        if (! $hub->isInstalled() || $hub->clientCertsPresent()) {
            return;
        }

        // Cache::add is atomic: it only succeeds when the key is absent, so
        // exactly one tick within the window dispatches the heal.
        if (! Cache::add('vector-hub:cert-autoheal', true, now()->addMinutes(5))) {
            return;
        }

        $record = VectorHubInstall::create(['status' => 'queued']);
        SyncHubCertsJob::dispatch($record->id);

        $this->warn('Vector Hub client certificates missing — dispatched a self-heal re-sync.');
    }
}
