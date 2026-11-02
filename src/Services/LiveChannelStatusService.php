<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Rconfig\VectorServer\Models\Agent;

/**
 * Single source of truth for Vector Hub live-channel status (RCO-1605).
 *
 * The hub knows, in real time, which agents are connected. Historically only
 * the per-minute `vector:hub-sync-status` command asked it and wrote the result
 * onto `agents.live_channel_*`, so the CLI picker and the UI read a column that
 * could be up to a minute stale (or wiped by `migrate:fresh`). This service
 * centralises that refresh so the command, the `vector:access` CLI, and the API
 * controllers all get status that is fresh at the moment of use, while a short
 * cache keeps polling and repeated reads from hammering the hub.
 */
class LiveChannelStatusService
{
    /** Seconds a refreshed snapshot is reused before the hub is polled again. */
    public const CACHE_SECONDS = 10;

    private const CACHE_KEY = 'vector-hub:live-status';

    public function __construct(private VectorHubClient $hub) {}

    /**
     * Refresh live-channel status from the hub and persist it onto agents.
     *
     * Calls within CACHE_SECONDS are coalesced so a page poll or the CLI does
     * not hit the hub once per request. When the hub is unreachable the agent
     * columns are left untouched (anti-flap) and the snapshot reports
     * `reachable = false` so callers can show a distinct "unknown" state rather
     * than a misleading "disconnected".
     *
     * @return array{reachable: bool, checked_at: string, statuses: array<int, array<string, mixed>>}
     */
    public function refresh(bool $force = false): array
    {
        if (! $force) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if (! $this->hub->isConfigured()) {
            return $this->snapshot(false, []);
        }

        $reported = $this->hub->agents();
        if ($reported === null) {
            // Hub unreachable: keep the last known DB values on purpose.
            return $this->snapshot(false, []);
        }

        $reportedIds = [];
        foreach ($reported as $status) {
            $agentId = (int) ($status['agent_id'] ?? 0);
            if ($agentId < 1) {
                continue;
            }
            $reportedIds[] = $agentId;

            Agent::where('id', $agentId)->update([
                'live_channel_connected' => (bool) ($status['live'] ?? false),
                'live_channel_rtt_ms' => $status['rtt_ms'] ?? null,
                'live_channel_last_seen_at' => isset($status['last_seen'])
                    ? Carbon::parse($status['last_seen'])
                    : now(),
            ]);
        }

        // Any agent still flagged connected that the hub no longer reports has dropped off.
        Agent::where('live_channel_connected', true)
            ->whereNotIn('id', $reportedIds)
            ->update(['live_channel_connected' => false, 'live_channel_rtt_ms' => null]);

        return $this->snapshot(true, $reported);
    }

    /**
     * Build and cache the snapshot callers receive.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array{reachable: bool, checked_at: string, statuses: array<int, array<string, mixed>>}
     */
    private function snapshot(bool $reachable, array $statuses): array
    {
        $snapshot = [
            'reachable' => $reachable,
            'checked_at' => now()->toIso8601String(),
            'statuses' => array_values($statuses),
        ];

        Cache::put(self::CACHE_KEY, $snapshot, self::CACHE_SECONDS);

        return $snapshot;
    }
}
