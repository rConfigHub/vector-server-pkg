<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\RespondsWithHttpStatus;
use Illuminate\Http\Request;
use Rconfig\VectorServer\Jobs\HubServiceActionJob;
use Rconfig\VectorServer\Jobs\InstallVectorHubJob;
use Rconfig\VectorServer\Jobs\SyncHubCertsJob;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;
use Rconfig\VectorServer\Models\VectorHubInstall;
use Rconfig\VectorServer\Services\VectorHubClient;

/**
 * SPA-facing Vector Hub management (RCO-744 Phase 2). Stage A is read-only:
 * report whether the hub is installed/configured and whether it is healthy.
 * Install + logs land in Stage B.
 */
class VectorHubManagementController extends Controller
{
    use RespondsWithHttpStatus;

    public function status(VectorHubClient $hub)
    {
        $this->authorize('agent.view');

        $installed = $hub->isInstalled();
        $configured = $hub->isConfigured();
        $clientCertsOk = $hub->clientCertsPresent();
        $healthy = $configured ? $hub->health() : false;

        // Distinguish "the mTLS client material Laravel presents to the hub is
        // missing" from a bare "not responding": the former is self-healable by
        // re-copying from the hub's tls dir (SyncHubCertsJob), the latter is not.
        $certsMissing = $installed && $configured && ! $clientCertsOk;

        $agents = [];
        $connectedAgents = null;
        if ($healthy) {
            $reported = $hub->agents();
            if (is_array($reported)) {
                $connectedAgents = count($reported);

                $ids = array_filter(array_map(static fn ($a) => (int) ($a['agent_id'] ?? 0), $reported));
                $names = $ids ? Agent::whereIn('id', $ids)->pluck('name', 'id') : collect();

                foreach ($reported as $a) {
                    $id = (int) ($a['agent_id'] ?? 0);
                    $agents[] = [
                        'agent_id' => $a['agent_id'] ?? null,
                        'name' => $names[$id] ?? null,
                        'connected_at' => $a['connected_at'] ?? null,
                        'last_seen' => $a['last_seen'] ?? null,
                        'rtt_ms' => $a['rtt_ms'] ?? null,
                        'streams' => $a['streams'] ?? null,
                        'remote_addr' => $a['remote_addr'] ?? null,
                    ];
                }
            }
        }

        return $this->successResponse('Vector Hub status', [
            'installed' => $installed,
            'configured' => $configured,
            'healthy' => $healthy,
            'client_certs_ok' => $clientCertsOk,
            'certs_missing' => $certsMissing,
            'connected_agents' => $connectedAgents,
            'agents' => $agents,
            'api_url' => config('vector-server.hub.api_url'),
            'tunnel_url' => config('vector-server.hub.tunnel_url'),
            'app_url' => config('app.url'),
        ]);
    }

    /**
     * All agents with their live-channel desired-state and current connection
     * status — so the UI can show connected agents AND ones that are enabled
     * but not connected, or not yet enabled (with an Enable action).
     */
    public function agents(VectorHubClient $hub)
    {
        $this->authorize('agent.view');

        $connected = [];
        if ($hub->isConfigured() && $hub->health()) {
            $reported = $hub->agents();
            if (is_array($reported)) {
                foreach ($reported as $a) {
                    $connected[(int) ($a['agent_id'] ?? 0)] = $a;
                }
            }
        }

        $agents = Agent::query()
            ->orderBy('name')
            ->get(['id', 'name', 'live_channel_enabled'])
            ->map(function ($agent) use ($connected) {
                $c = $connected[$agent->id] ?? null;

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'live_channel_enabled' => (bool) $agent->live_channel_enabled,
                    'connected' => $c !== null,
                    'rtt_ms' => $c['rtt_ms'] ?? null,
                    'connected_at' => $c['connected_at'] ?? null,
                    'last_seen' => $c['last_seen'] ?? null,
                ];
            });

        return $this->successResponse('Vector Hub agents', ['agents' => $agents]);
    }

    /**
     * Restart (or start, if stopped) the vector-hub service. Runs via the root
     * Horizon worker; poll the result through installStatus().
     */
    public function restartService(Request $request)
    {
        $this->authorize('agent.update');

        $record = VectorHubInstall::create([
            'status' => 'queued',
            'user_id' => optional($request->user())->id,
        ]);
        HubServiceActionJob::dispatch($record->id, 'restart');

        return $this->successResponse('Vector Hub restart queued.', ['id' => $record->id]);
    }

    /**
     * Self-heal the Laravel-side hub client certificates. Re-copies the CA +
     * client cert/key from the hub's tls dir into storage/app/vector-hub via
     * the root Horizon worker (apache cannot read the root-owned source). Poll
     * the result through installStatus().
     */
    public function resyncCerts(Request $request, VectorHubClient $hub)
    {
        $this->authorize('agent.update');

        if (! $hub->isInstalled()) {
            return $this->failureResponse('Vector Hub is not installed.', 422);
        }

        $record = VectorHubInstall::create([
            'status' => 'queued',
            'user_id' => optional($request->user())->id,
        ]);
        SyncHubCertsJob::dispatch($record->id);

        return $this->successResponse('Certificate re-sync queued.', ['id' => $record->id]);
    }

    /** Enable or disable the live channel (desired state) for one agent. */
    public function setAgentLiveChannel($id, Request $request)
    {
        $this->authorize('agent.update');

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $agent = Agent::findOrFail($id);
        $agent->live_channel_enabled = $validated['enabled'];
        $agent->save();

        AgentLog::create([
            'agent_id' => $agent->id,
            'executed_at' => now(),
            'log_level' => 'INFO',
            'message' => "Live channel {$this->onOff($validated['enabled'])} for agent {$agent->name}",
            'operation' => 'live_channel_' . ($validated['enabled'] ? 'enabled' : 'disabled'),
            'entity_type' => 'Agent',
            'entity_id' => $agent->id,
        ]);

        return $this->successResponse(
            "Live channel {$this->onOff($validated['enabled'])} for {$agent->name}.",
            ['id' => $agent->id, 'live_channel_enabled' => $agent->live_channel_enabled]
        );
    }

    private function onOff(bool $enabled): string
    {
        return $enabled ? 'enabled' : 'disabled';
    }

    /**
     * Queue an install. The install runs as root via the Horizon worker
     * (InstallVectorHubJob); apache cannot install a system service itself.
     * Every operator flag is validated here before it reaches that root shell.
     */
    public function install(Request $request)
    {
        $this->authorize('agent.update');

        $validated = $request->validate([
            'rconfig_url' => ['required', 'url', 'max:255'],
            'tunnel_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'auth_ca' => ['nullable', 'string', 'max:1024'],
            'tunnel_cert' => ['nullable', 'string', 'max:1024'],
            'tunnel_key' => ['nullable', 'string', 'max:1024'],
            'hub_bin' => ['nullable', 'string', 'max:1024'],
            'insecure_download' => ['sometimes', 'boolean'],
        ]);

        // File-path flags must be absolute, plainly-named, and actually exist.
        foreach (['auth_ca', 'tunnel_cert', 'tunnel_key', 'hub_bin'] as $field) {
            if (! empty($validated[$field])) {
                $path = $validated[$field];
                if (! preg_match('#^/[A-Za-z0-9._/-]+$#', $path) || ! is_file($path)) {
                    return $this->failureResponse("Invalid or missing file for {$field}.", 422);
                }
            }
        }
        if (! empty($validated['tunnel_cert']) !== ! empty($validated['tunnel_key'])) {
            return $this->failureResponse('tunnel_cert and tunnel_key must be provided together.', 422);
        }

        // Build the argv flag list — discrete args, never a shell string.
        $args = ['--rconfig-url', $validated['rconfig_url'], '--tunnel-host', $validated['tunnel_host']];
        if (! empty($validated['auth_ca'])) {
            array_push($args, '--auth-ca', $validated['auth_ca']);
        }
        if (! empty($validated['tunnel_cert'])) {
            array_push($args, '--tunnel-cert', $validated['tunnel_cert'], '--tunnel-key', $validated['tunnel_key']);
        }
        if (! empty($validated['hub_bin'])) {
            array_push($args, '--hub-bin', $validated['hub_bin']);
        }
        if (! empty($validated['insecure_download'])) {
            $args[] = '--insecure-download';
        }

        $install = VectorHubInstall::create([
            'status' => 'queued',
            'user_id' => optional($request->user())->id,
        ]);
        InstallVectorHubJob::dispatch($install->id, $args);

        return $this->successResponse('Vector Hub install queued.', ['id' => $install->id]);
    }

    /**
     * Queue a complete uninstall (stop the service, remove the binary, certs,
     * config, service account, and clear the rConfig .env keys). Runs the same
     * script in --uninstall mode via the root Horizon worker.
     */
    public function uninstall(Request $request)
    {
        $this->authorize('agent.update');

        $install = VectorHubInstall::create([
            'status' => 'queued',
            'user_id' => optional($request->user())->id,
        ]);
        InstallVectorHubJob::dispatch($install->id, ['--uninstall']);

        return $this->successResponse('Vector Hub uninstall queued.', ['id' => $install->id]);
    }

    /** Poll an install/uninstall run's progress/output. */
    public function installStatus($id)
    {
        $this->authorize('agent.view');

        $install = VectorHubInstall::findOrFail($id);

        return $this->successResponse('Install status', [
            'id' => $install->id,
            'status' => $install->status,
            'exit_code' => $install->exit_code,
            'service_status' => $install->service_status,
            'output' => $install->output,
            'finished_at' => $install->finished_at,
        ]);
    }
}
