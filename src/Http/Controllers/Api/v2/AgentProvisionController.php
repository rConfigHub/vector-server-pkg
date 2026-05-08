<?php

namespace Rconfig\VectorServer\Http\Controllers\Api\v2;

use App\Http\Controllers\Controller;
use App\Traits\RespondsWithHttpStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Http\Requests\ProvisionAgentRequest;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\VectorAgentBootstrapToken;
use Rconfig\VectorServer\Services\AgentAccess\AgentSourceAllowlistService;
use Rconfig\VectorServer\Services\BootstrapTokenService;

class AgentProvisionController extends Controller
{
    use RespondsWithHttpStatus;

    private const DEFAULT_BOOTSTRAP_TTL_MINUTES = 60;

    private const DEFAULT_ROLE_ID = 1;

    public function provision(
        ProvisionAgentRequest $request,
        BootstrapTokenService $tokenService,
        AgentSourceAllowlistService $allowlistService
    ): JsonResponse {
        $payload = $request->validated();
        $ttlMinutes = (int) ($payload['bootstrap_ttl_minutes'] ?? self::DEFAULT_BOOTSTRAP_TTL_MINUTES);
        $roleIds = $this->normaliseRoleIds($payload['roles'] ?? null);

        // The form request has already normalised an explicit `srcip` into
        // request->input('srcip_allowlist'). If the caller didn't pass one but
        // opted into srcip_from_request, derive the allowlist from the caller's
        // IP here. We don't do this by default — orchestrators (Ansible, CI)
        // call from a different host than the agent runs on, so an automatic
        // default would lock the actual agent out.
        $srcip = $payload['srcip'] ?? null;
        $srcipAllowlist = $request->input('srcip_allowlist');

        if (($srcip === null || $srcip === '') && ! empty($payload['srcip_from_request'])) {
            $callerIp = $request->ip();
            if ($callerIp !== null && $callerIp !== '') {
                $candidate = str_contains($callerIp, ':') ? $callerIp . '/128' : $callerIp . '/32';
                $allowlist = $allowlistService->normalizeRawInput($candidate);

                if (empty($allowlist['errors'])) {
                    $srcip = $allowlist['normalized_input'];
                    $srcipAllowlist = $allowlist['entries'];
                }
            }
        }

        [$agent, $created] = DB::transaction(function () use ($payload, $roleIds, $srcip, $srcipAllowlist) {
            $existing = Agent::where('name', $payload['name'])->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $attributes = [
                'name' => $payload['name'],
                'email' => $payload['email'] ?? null,
                'srcip' => $srcip,
                'api_token' => Str::uuid()->toString(),
                'status' => 0,
                'agent_debug' => 0,
                'ssl_verify' => true,
                'retry_count' => 3,
                'retry_interval' => 10,
                'job_retry_count' => 1,
                'checkin_interval' => $payload['checkin_interval'] ?? 300,
                'queue_download_rate' => 300,
                'log_upload_rate' => 300,
                'worker_count' => $payload['worker_count'] ?? 5,
                'max_missed_checkins' => $payload['max_missed_checkins'] ?? 3,
                'is_admin_enabled' => 1,
            ];

            if (Schema::hasColumn('agents', 'srcip_allowlist')) {
                $attributes['srcip_allowlist'] = $srcipAllowlist;
            }

            $agent = Agent::create($attributes);
            $agent->roles()->sync($roleIds);

            return [$agent, true];
        });

        VectorAgentBootstrapToken::where('agent_id', $agent->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $rawToken = $tokenService->generate($agent->id, $ttlMinutes);
        $tokenHash = hash('sha256', $rawToken);
        $token = VectorAgentBootstrapToken::where('token_hash', $tokenHash)->first();
        $expiresAt = $token?->expires_at ?? now()->addMinutes($ttlMinutes);

        $serverUrl = $request->getSchemeAndHttpHost();
        $installCommand = 'curl -kfsSL "' . $serverUrl . '/vector/install.sh?bootstrap_token=' . $rawToken . '" | bash';

        $message = $created
            ? 'Agent provisioned successfully.'
            : 'Agent already existed; new bootstrap token issued.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'created' => $created,
                'install_command' => $installCommand,
                'bootstrap_token' => $rawToken,
                'expires_at' => $expiresAt,
            ],
        ], $created ? 201 : 200);
    }

    /**
     * @param  array<int, int|string>|null  $roles
     * @return array<int, int>
     */
    private function normaliseRoleIds(?array $roles): array
    {
        if (empty($roles)) {
            return [self::DEFAULT_ROLE_ID];
        }

        $ids = array_values(array_unique(array_map('intval', $roles)));

        if (! in_array(self::DEFAULT_ROLE_ID, $ids, true)) {
            $ids[] = self::DEFAULT_ROLE_ID;
        }

        return $ids;
    }
}
