<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Services\VectorHubClient;

/**
 * Nudge one agent to pick up its queued jobs now, over the live channel
 * (RCO-1155). Dispatched when a job is enqueued for a live-channel agent so
 * work starts without polling lag. Best-effort: if the agent is not connected
 * or the hub is unreachable, the agent still picks the job up on its next tick.
 */
class PokeAgentLiveChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 15;

    public function __construct(public int $agentId) {}

    public function handle(VectorHubClient $hub): void
    {
        if (! $hub->isConfigured()) {
            return;
        }

        // Re-check at run time: the desired state may have changed since the
        // enqueue that scheduled this nudge.
        $enabled = Agent::query()
            ->whereKey($this->agentId)
            ->where('live_channel_enabled', true)
            ->exists();
        if (! $enabled) {
            return;
        }

        $hub->pollAgent($this->agentId); // rescued internally; a miss is harmless
    }
}
