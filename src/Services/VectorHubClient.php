<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Vector Hub control API (RCO-744): agent liveness and
 * RTT out of the hub, Validate pings into it. Speaks mTLS when the install
 * script has provisioned client credentials; plain HTTP in dev.
 */
class VectorHubClient
{
    /** Default install path written by install-hub.sh; overridable via config. */
    private const DEFAULT_BIN_PATH = '/usr/local/bin/rconfig/activehub/vector-hub';

    public function isConfigured(): bool
    {
        return ! empty(config('vector-server.hub.api_url'));
    }

    /** Absolute path to the installed hub binary. */
    public function binPath(): string
    {
        return (string) config('vector-server.hub.bin_path', self::DEFAULT_BIN_PATH);
    }

    /** Whether the hub binary is present — how the UI detects an install. */
    public function isInstalled(): bool
    {
        return is_file($this->binPath());
    }

    /**
     * Whether the mTLS client material Laravel presents to the hub exists and
     * is readable by the web user. Missing files are the recoverable case the
     * self-heal (SyncHubCertsJob) targets; an unconfigured hub has no paths.
     */
    public function clientCertsPresent(): bool
    {
        foreach ([
            config('vector-server.hub.client_cert'),
            config('vector-server.hub.client_key'),
            config('vector-server.hub.ca_cert'),
        ] as $path) {
            if (empty($path) || ! is_file($path) || ! is_readable($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * All agents currently connected to the hub, or null when the hub is
     * unreachable (distinct from an empty list, which means "reachable,
     * nobody connected").
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function agents(): ?array
    {
        $response = $this->rescue(fn () => $this->request()->get($this->url('/agents')));
        if (! $response || ! $response->successful()) {
            return null;
        }

        return $response->json('agents');
    }

    /**
     * On-demand liveness proof: asks the hub to ping the agent right now.
     *
     * @return array<string, mixed>|null Fresh RTT payload, or null if the
     *                                   agent is not connected or unreachable.
     */
    public function validateAgent(int|string $agentId): ?array
    {
        $response = $this->rescue(fn () => $this->request()->post($this->url('/agents/' . $agentId . '/validate')));
        if (! $response || ! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Ask the hub to restart an agent over the live channel. The agent exits
     * gracefully and its service manager brings it back.
     *
     * @return array<string, mixed>|null Hub response, or null if the agent is
     *                                   not connected or the hub is unreachable.
     */
    public function restartAgent(int|string $agentId): ?array
    {
        $response = $this->rescue(fn () => $this->request()->post($this->url('/agents/' . $agentId . '/restart')));
        if (! $response || ! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function health(): bool
    {
        $response = $this->rescue(fn () => $this->request()->get($this->url('/health')));

        return $response !== null && $response->successful();
    }

    protected function request(): PendingRequest
    {
        // The hub's control API requires the shared secret in code, not just
        // mTLS — so a cert misconfiguration can never leave it open.
        $request = Http::timeout(10)
            ->acceptJson()
            ->withToken((string) config('vector-server.hub.auth_secret'));

        $options = [];
        if ($cert = config('vector-server.hub.client_cert')) {
            $options['cert'] = $cert;
        }
        if ($key = config('vector-server.hub.client_key')) {
            $options['ssl_key'] = $key;
        }
        if ($ca = config('vector-server.hub.ca_cert')) {
            $options['verify'] = $ca;
        }

        return $options === [] ? $request : $request->withOptions($options);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('vector-server.hub.api_url'), '/') . $path;
    }

    /**
     * Connection-level failures (hub down, TLS refused) become null instead
     * of an exception — callers treat the hub as unreachable, never fatal.
     */
    protected function rescue(callable $call)
    {
        try {
            return $call();
        } catch (\Exception $e) {
            Log::warning('Vector Hub unreachable', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
