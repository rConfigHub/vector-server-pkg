<?php

namespace Rconfig\VectorServer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Rconfig\VectorServer\Jobs\SyncHubCertsJob;
use Rconfig\VectorServer\Models\VectorHubInstall;
use Rconfig\VectorServer\Services\LiveChannelStatusService;
use Rconfig\VectorServer\Services\VectorHubClient;

class VectorHubSyncStatusCmd extends Command
{
    protected $signature = 'vector:hub-sync-status';
    protected $description = 'Persist Vector Hub live-channel status (connected, RTT, last seen) onto agents';

    public function handle(LiveChannelStatusService $status, VectorHubClient $hub)
    {
        if (! $hub->isConfigured()) {
            return 0;
        }

        // The write logic now lives in LiveChannelStatusService so the CLI and
        // the API share it (RCO-1605). force: true always polls the hub so this
        // scheduled backstop never serves a cached snapshot.
        $snapshot = $status->refresh(force: true);

        if (! $snapshot['reachable']) {
            // Hub unreachable is not proof agents dropped — refresh() kept the
            // last observed state rather than flapping everyone to disconnected.
            // A common, recoverable cause is the Laravel client certs going
            // missing (the mTLS handshake fails and every call reads as down),
            // so try to self-heal them here before giving up for this tick.
            $this->selfHealClientCerts($hub);

            $this->warn('Vector Hub unreachable; live-channel status left unchanged.');

            return 1;
        }

        $this->line('Synced live-channel status for ' . count($snapshot['statuses']) . ' connected agent(s).');

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
