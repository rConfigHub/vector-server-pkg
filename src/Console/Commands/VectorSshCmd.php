<?php

namespace Rconfig\VectorServer\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use function Laravel\Prompts\search;

use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;
use Rconfig\VectorServer\Services\SshSessionLogger;

/**
 * Opens an interactive SSH session to a device *through its Vector Agent*
 * (RCO-1155), over the live channel — no inbound access to the device or the
 * agent required.
 *
 * The operator never sees the device credentials: this command resolves them
 * server-side and hands them to the hub, which passes them to the agent to
 * use. Access is therefore governed by rConfig, not by who knows the device
 * password.
 */
class VectorSshCmd extends Command
{
    protected $signature = 'vector:ssh
        {device? : Device id or name; omit to pick interactively}
        {--log : Record this session (device, agent, and full transcript) to storage/logs/vector-ssh}';
    protected $description = 'Open an interactive SSH session to a device through its Vector Agent';

    /** Original terminal settings, restored on exit. */
    private ?string $sttyState = null;

    public function handle(): int
    {
        $sessionAddr = config('vector-server.hub.session_addr');
        if (empty($sessionAddr)) {
            $this->error('vector-server.hub.session_addr is not configured (VECTOR_HUB_SESSION_ADDR).');

            return self::FAILURE;
        }

        $key = $this->argument('device');
        $device = $key === null ? $this->pickDevice() : $this->resolveDevice($key);
        if (! $device) {
            return self::FAILURE;
        }

        [$username, $password, $privateKey, $passphrase] = $this->resolveCredentials($device);
        if ($username === '') {
            $this->error("Device {$device->device_name} has no usable SSH credentials.");

            return self::FAILURE;
        }

        $socket = $this->connectToHub($sessionAddr);
        if (! $socket) {
            return self::FAILURE;
        }

        // EncryptStringCast decrypts the password/key on access; they go
        // straight to the hub over loopback and are never printed or logged.
        $header = [
            'secret' => (string) config('vector-server.hub.auth_secret'),
            'agent_id' => (string) $device->agent_id,
            'device_id' => (int) $device->id,
            'protocol' => 'ssh',
            'host' => $device->device_ip,
            'port' => (string) ($device->device_port_override ?: 22),
            'username' => $username,
            'password' => $password,
            'private_key' => $privateKey,
            'passphrase' => $passphrase,
            'term' => getenv('TERM') ?: 'xterm',
            'rows' => (int) $this->terminalRows(),
            'cols' => (int) $this->terminalCols(),
        ];

        fwrite($socket, json_encode($header) . "\n");

        $this->line("Connecting to {$device->device_name} ({$device->device_ip}) via agent {$device->agent_id}... press Ctrl-D or exit to end.");
        Log::info('vector:ssh session started', [
            'device_id' => $device->id,
            'agent_id' => $device->agent_id,
            'user' => get_current_user(),
        ]);

        $logger = $this->option('log') ? $this->startSessionLog($device, $username) : null;

        $this->pipe($socket, $logger);

        $logger?->close();
        fclose($socket);
        $this->restoreTerminal();
        $this->newLine();
        $this->info('Session ended.');
        Log::info('vector:ssh session ended', ['device_id' => $device->id]);
        if ($logger) {
            $this->line("Session transcript saved to {$logger->path()}");
        }

        return self::SUCCESS;
    }

    /**
     * Open a transcript for this session and record an audit entry noting the
     * device and agent used. Credentials are never written to either.
     */
    private function startSessionLog(Device $device, string $username): ?SshSessionLogger
    {
        $operator = get_current_user() ?: 'unknown';
        $agent = Agent::find($device->agent_id);

        try {
            $logger = new SshSessionLogger([
                'operator' => $operator,
                'agent_id' => $device->agent_id,
                'agent_name' => $agent?->name,
                'device_id' => $device->id,
                'device_name' => (string) $device->device_name,
                'device_ip' => (string) $device->device_ip,
                'device_port' => $device->device_port_override ?: 22,
                'username' => $username,
            ]);
        } catch (\Throwable $e) {
            // Logging is best-effort — never block a session because the
            // transcript file could not be opened.
            $this->warn('Could not start session logging: ' . $e->getMessage());
            Log::warning('vector:ssh session logging failed to start', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return null;
        }

        $this->line("Session logging enabled → {$logger->path()}");
        Log::info('vector:ssh session logging enabled', [
            'device_id' => $device->id,
            'agent_id' => $device->agent_id,
            'operator' => $operator,
            'path' => $logger->path(),
        ]);

        AgentLog::create([
            'agent_id' => $device->agent_id,
            'executed_at' => now(),
            'log_level' => 'INFO',
            'message' => "Logged SSH session opened to {$device->device_name} ({$device->device_ip}) by {$operator}",
            'operation' => 'live_ssh_session_logged',
            'entity_type' => 'Device',
            'entity_id' => $device->id,
        ]);

        return $logger;
    }

    private function pickDevice(): ?Device
    {
        $agentId = $this->pickAgent();
        if ($agentId === null) {
            return null;
        }

        $deviceCount = Device::where('agent_id', $agentId)->count();
        if ($deviceCount === 0) {
            $this->error('That agent has no devices assigned.');

            return null;
        }

        $deviceId = search(
            label: 'Which device?',
            placeholder: 'Type to filter by name or IP',
            options: fn (string $value) => Device::where('agent_id', $agentId)
                ->when($value !== '', fn ($q) => $q->where(
                    fn ($w) => $w->where('device_name', 'like', "%{$value}%")
                        ->orWhere('device_ip', 'like', "%{$value}%")
                ))
                ->orderBy('device_name')
                ->limit(50)
                ->get(['id', 'device_name', 'device_ip'])
                ->mapWithKeys(fn ($d) => [$d->id => "{$d->device_name}  ({$d->device_ip})"])
                ->all(),
            scroll: 15,
        );

        return Device::find($deviceId);
    }

    /**
     * Choose an agent to connect through. Only agents whose live channel is
     * currently connected are offered — a session cannot be opened otherwise —
     * and the list is searchable by name, like the device picker.
     */
    private function pickAgent(): ?int
    {
        if ($this->connectedAgentOptions() === []) {
            $this->error('No agents have a live channel connected. Enable the live channel on an agent and wait for it to connect.');

            return null;
        }

        $agentId = search(
            label: 'Which agent?',
            placeholder: 'Type to filter by name',
            options: fn (string $value) => $this->connectedAgentOptions($value),
            scroll: 15,
        );

        return $agentId !== null ? (int) $agentId : null;
    }

    /**
     * Agents eligible for an interactive session: admin-enabled and with the
     * live channel currently connected, optionally filtered by name.
     *
     * @return array<int, string> agent id => display label
     */
    protected function connectedAgentOptions(string $filter = ''): array
    {
        return Agent::query()
            ->where('is_admin_enabled', Agent::ADMIN_ENABLED)
            ->where('live_channel_connected', true)
            ->when($filter !== '', fn ($q) => $q->where('name', 'like', "%{$filter}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'live_channel_rtt_ms'])
            ->mapWithKeys(fn ($a) => [
                $a->id => $a->name . ($a->live_channel_rtt_ms !== null ? "  RTT - {$a->live_channel_rtt_ms}ms" : '  (live)'),
            ])
            ->all();
    }

    /**
     * Resolve the device's SSH credentials exactly as rConfig's own device
     * loader does: a linked credential set wins, otherwise the device falls
     * back to its own stored username and password.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [username, password, privateKey, passphrase]
     */
    private function resolveCredentials(Device $device): array
    {
        if ((int) $device->device_cred_id !== 0) {
            $cred = $device->deviceCred;

            return $cred
                ? [
                    (string) $cred->cred_username,
                    (string) $cred->cred_password,
                    (string) ($cred->ssh_key ?? ''),
                    (string) ($cred->ssh_key_passphrase ?? ''),
                ]
                : ['', '', '', ''];
        }

        // Device-level fallback credentials carry no SSH key.
        return [(string) $device->device_username, (string) $device->device_password, '', ''];
    }

    private function resolveDevice(string $key): ?Device
    {
        $device = is_numeric($key)
            ? Device::find((int) $key)
            : Device::where('device_name', $key)->first();

        if (! $device) {
            $this->error("Device not found: {$key}");

            return null;
        }
        if (! $device->agent_id) {
            $this->error("Device {$device->device_name} is not assigned to an agent.");

            return null;
        }

        return $device;
    }

    /**
     * @return resource|null
     */
    private function connectToHub(string $addr)
    {
        // The broker listens on loopback; a plain socket keeps this command
        // free of any framing logic.
        $socket = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 10);
        if (! $socket) {
            $this->error("Cannot reach the Vector Hub session broker at {$addr}: {$errstr}");

            return null;
        }
        stream_set_blocking($socket, false);

        return $socket;
    }

    /**
     * Pump bytes between this terminal and the hub until either side closes.
     * When a logger is supplied, the rendered device output is also recorded.
     *
     * @param  resource  $socket
     */
    private function pipe($socket, ?SshSessionLogger $logger = null): void
    {
        $stdin = fopen('php://stdin', 'r');
        stream_set_blocking($stdin, false);
        $this->makeTerminalRaw();

        while (true) {
            $read = [$socket, $stdin];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break; // interrupted (e.g. window resize signal)
            }

            foreach ($read as $stream) {
                if ($stream === $socket) {
                    $data = fread($socket, 32768);
                    if ($data === '' || $data === false) {
                        if (feof($socket)) {
                            return; // agent/device closed the session
                        }

                        continue;
                    }
                    fwrite(STDOUT, $data);
                    $logger?->append($data);
                } else {
                    $data = fread($stdin, 4096);
                    if ($data === '' || $data === false) {
                        continue;
                    }
                    fwrite($socket, $data);
                }
            }
        }
    }

    /**
     * Put the terminal in raw mode so keystrokes reach the device
     * immediately and the device — not the local shell — does the echoing.
     */
    private function makeTerminalRaw(): void
    {
        if (! $this->hasStty()) {
            return;
        }
        $this->sttyState = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty raw -echo 2>/dev/null');
    }

    private function restoreTerminal(): void
    {
        if ($this->sttyState !== null && $this->sttyState !== '') {
            shell_exec('stty ' . escapeshellarg($this->sttyState) . ' 2>/dev/null');
            $this->sttyState = null;
        }
    }

    private function hasStty(): bool
    {
        return function_exists('shell_exec') && stream_isatty(STDIN);
    }

    private function terminalRows(): int
    {
        $rows = $this->hasStty() ? (int) trim((string) shell_exec('tput lines 2>/dev/null')) : 0;

        return $rows > 0 ? $rows : 24;
    }

    private function terminalCols(): int
    {
        $cols = $this->hasStty() ? (int) trim((string) shell_exec('tput cols 2>/dev/null')) : 0;

        return $cols > 0 ? $cols : 80;
    }
}
