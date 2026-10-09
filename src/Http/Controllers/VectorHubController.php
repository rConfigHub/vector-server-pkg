<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;
use Rconfig\VectorServer\Services\AgentAccess\AgentSourceAllowlistService;

/**
 * Endpoints the Vector Hub calls back into (RCO-744). Routes are protected
 * by VectorHubCheckAccess (shared secret), so callers here are always the
 * hub process itself.
 */
class VectorHubController extends Controller
{
    /**
     * Resolve an agent api_token to its agent id for a tunnel dial. Mirrors
     * the checks AgentCheckApiSyncAccess applies to the polling API: token
     * validity, admin enablement, and the source-IP allowlist — plus the
     * live-channel desired state, which polling does not require.
     */
    public function authenticateAgent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'remote_ip' => ['nullable', 'ip'],
        ]);

        if (! Str::isUuid($validated['token'])) {
            Log::warning('Vector Hub agent auth: invalid token format', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid token'], 401);
        }

        $agent = Agent::where('api_token', $validated['token'])->first();
        if (! $agent) {
            Log::warning('Vector Hub agent auth: unknown token', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid token'], 401);
        }

        if (! $agent->isAdminEnabled()) {
            Log::warning('Vector Hub agent auth: agent is admin disabled', ['agent_id' => $agent->id]);

            return response()->json(['error' => 'Agent is disabled by admin'], 403);
        }

        if (! $agent->live_channel_enabled) {
            Log::warning('Vector Hub agent auth: live channel not enabled', ['agent_id' => $agent->id]);

            return response()->json(['error' => 'Live channel is not enabled for this agent'], 403);
        }

        if (! empty($validated['remote_ip'])) {
            $sourceCheck = app(AgentSourceAllowlistService::class)
                ->isRequestIpAllowed($agent, $validated['remote_ip']);
            if (! $sourceCheck['allowed']) {
                Log::warning('Vector Hub agent auth: source IP not allowed', [
                    'agent_id' => $agent->id,
                    'remote_ip' => $validated['remote_ip'],
                    'reason' => $sourceCheck['reason'],
                ]);

                return response()->json(['error' => 'Unauthorized IP address'], 403);
            }
        }

        AgentLog::create([
            'agent_id' => $agent->id,
            'executed_at' => now(),
            'log_level' => 'INFO',
            'message' => "Agent {$agent->name} authenticated to the Vector Hub live channel",
            'operation' => 'live_channel_auth',
            'context_data' => json_encode(['remote_ip' => $validated['remote_ip'] ?? null]),
            'entity_type' => 'Agent',
            'entity_id' => $agent->id,
        ]);

        return response()->json([
            'agent_id' => (string) $agent->id,
            'agent_name' => $agent->name,
        ]);
    }
}
