<?php

namespace Rconfig\VectorServer\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Rconfig\VectorServer\Models\Agent;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

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
    protected $signature = 'vector:ssh {device? : Device id or name; omit to pick interactively}';

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

        [$username, $password] = $this->resolveCredentials($device);
        if ($username === '') {
            $this->error("Device {$device->device_name} has no usable SSH credentials.");

            return self::FAILURE;
        }

        $socket = $this->connectToHub($sessionAddr);
        if (! $socket) {
            return self::FAILURE;
        }

        // EncryptStringCast decrypts the password on access; it goes straight
        // to the hub over loopback and is never printed or logged.
        $header = [
            'secret' => (string) config('vector-server.hub.auth_secret'),
            'agent_id' => (string) $device->agent_id,
            'device_id' => (int) $device->id,
            'protocol' => 'ssh',
            'host' => $device->device_ip,
            'port' => (string) ($device->device_port_override ?: 22),
            'username' => $username,
            'password' => $password,
            'term' => getenv('TERM') ?: 'xterm',
            'rows' => (int) $this->terminalRows(),
            'cols' => (int) $this->terminalCols(),
        ];

        fwrite($socket, json_encode($header)."\n");

        $this->line("Connecting to {$device->device_name} ({$device->device_ip}) via agent {$device->agent_id}... press Ctrl-D or exit to end.");
        Log::info('vector:ssh session started', [
            'device_id' => $device->id,
            'agent_id' => $device->agent_id,
            'user' => get_current_user(),
        ]);

        $this->pipe($socket);

        fclose($socket);
        $this->restoreTerminal();
        $this->newLine();
        $this->info('Session ended.');
        Log::info('vector:ssh session ended', ['device_id' => $device->id]);

        return self::SUCCESS;
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
     * Choose an agent, showing whether its live channel is currently up —
     * a session can only be opened through a connected agent.
     */
    private function pickAgent(): ?int
    {
        $agents = Agent::where('is_admin_enabled', Agent::ADMIN_ENABLED)
            ->orderBy('name')
            ->get(['id', 'name', 'live_channel_connected', 'live_channel_rtt_ms']);

        if ($agents->isEmpty()) {
            $this->error('No enabled agents found.');

            return null;
        }

        $options = [];
        foreach ($agents as $agent) {
            $status = $agent->live_channel_connected
                ? 'live channel up'.($agent->live_channel_rtt_ms !== null ? ", {$agent->live_channel_rtt_ms} ms" : '')
                : 'live channel down';
            $count = Device::where('agent_id', $agent->id)->count();
            $options[$agent->id] = "{$agent->name}  —  {$status}, {$count} device(s)";
        }

        return (int) select(label: 'Which agent?', options: $options, scroll: 15);
    }

    /**
     * Resolve the device's SSH credentials exactly as rConfig's own device
     * loader does: a linked credential set wins, otherwise the device falls
     * back to its own stored username and password.
     *
     * @return array{0: string, 1: string} [username, password]
     */
    private function resolveCredentials(Device $device): array
    {
        if ((int) $device->device_cred_id !== 0) {
            $cred = $device->deviceCred;

            return $cred
                ? [(string) $cred->cred_username, (string) $cred->cred_password]
                : ['', ''];
        }

        return [(string) $device->device_username, (string) $device->device_password];
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
        $socket = @stream_socket_client('tcp://'.$addr, $errno, $errstr, 10);
        if (! $socket) {
            $this->error("Cannot reach the Vector Hub session broker at {$addr}: {$errstr}");

            return null;
        }
        stream_set_blocking($socket, false);

        return $socket;
    }

    /**
     * Pump bytes between this terminal and the hub until either side closes.
     *
     * @param  resource  $socket
     */
    private function pipe($socket): void
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
            shell_exec('stty '.escapeshellarg($this->sttyState).' 2>/dev/null');
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
