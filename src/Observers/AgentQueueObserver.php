<?php

namespace Rconfig\VectorServer\Observers;

use Illuminate\Support\Facades\Cache;
use Rconfig\VectorServer\Jobs\PokeAgentLiveChannelJob;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentQueue;

/**
 * When a job is enqueued for an agent, nudge that agent to pick it up now over
 * the live channel (RCO-1155) — but only for agents with the live channel
 * enabled. Debounced per agent so a burst of enqueues collapses to one nudge,
 * and dispatched to the queue so it never blocks or breaks the enqueue itself.
 */
class AgentQueueObserver
{
    /** One nudge per agent at most this often (seconds). */
    private const DEBOUNCE_SECONDS = 3;

    public function created(AgentQueue $item): void
    {
        $agentId = (int) $item->agent_id;
        if ($agentId < 1) {
            return;
        }

        // Coalesce bursts: only the first enqueue in the window schedules a nudge.
        if (! Cache::add("vector-hub:poll-nudge:{$agentId}", true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        // Only agents opted into the live channel get a push.
        $enabled = Agent::query()
            ->whereKey($agentId)
            ->where('live_channel_enabled', true)
            ->exists();
        if (! $enabled) {
            return;
        }

        PokeAgentLiveChannelJob::dispatch($agentId);
    }
}
