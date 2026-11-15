<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Controls the Vector Hub service on the rConfig server.
 *
 * Two modes (RCO-1738):
 *
 * - sudo (rConfig V8): Horizon runs as the web user and calls the root-owned
 *   wrapper through `sudo -n` (RCO-1794). rConfig's
 *   `rconfig:vector-hub-install-helper` installs the wrapper and a sudoers rule
 *   naming only it. The wrapper accepts fixed actions and checks every install
 *   flag itself; nothing here builds a shell string.
 *
 * - systemd (rConfig V9): Horizon runs as a Bosun systemd unit with
 *   NoNewPrivileges=yes, so sudo is refused. Start, stop and restart call
 *   `systemctl` on rconfig-vector-hub.service, which rConfig's polkit rule lets
 *   the web user manage. Install and uninstall need root and are run on the
 *   server with `php artisan rconfig:vector-hub`, never from Horizon.
 */
class VectorHubCtl
{
    public const DEFAULT_PATH = '/usr/local/libexec/rconfig/rconfig-vector-hub';

    public const DEFAULT_UNIT = 'rconfig-vector-hub.service';

    public const MODE_SUDO = 'sudo';

    public const MODE_SYSTEMD = 'systemd';

    /** Written by rConfig V9's `rconfig:bosun-units --install`; its Horizon runs with NoNewPrivileges. */
    public const BOSUN_HORIZON_UNIT = '/etc/systemd/system/rconfig-horizon.service';

    /** The root command that installs, upgrades or removes the hub in systemd mode. */
    public const ROOT_COMMAND = 'php artisan rconfig:vector-hub';

    /** @var array<int,string> */
    public const ACTIONS = ['install', 'uninstall', 'start', 'stop', 'restart', 'status'];

    /** @var array<int,string> */
    public const ROOT_ONLY_ACTIONS = ['install', 'uninstall'];

    public function path(): string
    {
        return (string) (config('vector-server.hub.ctl_path') ?: self::DEFAULT_PATH);
    }

    public function unit(): string
    {
        return (string) (config('vector-server.hub.unit') ?: self::DEFAULT_UNIT);
    }

    /**
     * The service name for job output. In sudo mode the wrapper controls the
     * unit its install script wrote: rconfig-vector-hub.service from rConfig
     * V9 (RCO-1738), vector-hub.service before.
     */
    public function serviceLabel(): string
    {
        return $this->usesSystemd() || $this->unitInstalled() ? $this->unit() : 'vector-hub';
    }

    /**
     * 'systemd' or 'sudo'. `auto` picks systemd when Bosun's Horizon unit is
     * installed, since sudo cannot work under its NoNewPrivileges. A file
     * check rather than this process's own flags, so the web request and the
     * Horizon job always agree.
     */
    public function mode(): string
    {
        $configured = strtolower((string) (config('vector-server.hub.control') ?: 'auto'));

        if (in_array($configured, [self::MODE_SUDO, self::MODE_SYSTEMD], true)) {
            return $configured;
        }

        return $this->bosunUnitsInstalled() ? self::MODE_SYSTEMD : self::MODE_SUDO;
    }

    public function usesSystemd(): bool
    {
        return $this->mode() === self::MODE_SYSTEMD;
    }

    /** Install and uninstall need root, so in systemd mode they run on the server instead. */
    public function isRootOnly(string $action): bool
    {
        return $this->usesSystemd() && in_array($action, self::ROOT_ONLY_ACTIONS, true);
    }

    /**
     * The command an operator runs as root for a root-only action.
     *
     * @param  array<int,string>  $flags
     */
    public function rootCommand(string $action, array $flags = []): string
    {
        $parts = array_map('escapeshellarg', array_values($flags));

        return trim('sudo ' . self::ROOT_COMMAND . ' ' . $action . ' ' . implode(' ', $parts));
    }

    /**
     * @param  array<int,string>  $flags
     * @return array<int,string>
     */
    public function command(string $action, array $flags = []): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unsupported Vector Hub action: {$action}");
        }

        if (! $this->usesSystemd()) {
            return array_merge(['sudo', '-n', $this->path(), $action], array_values($flags));
        }

        if ($this->isRootOnly($action)) {
            throw new \LogicException("Vector Hub {$action} needs root. On the server, run: " . $this->rootCommand($action, $flags));
        }

        return $action === 'status'
            ? ['systemctl', 'is-active', $this->unit()]
            : ['systemctl', $action, $this->unit()];
    }

    /**
     * The service state (first line of the status output) and the full status
     * output with the recent journal. State is null when the status could not
     * be read.
     *
     * @return array{0: ?string, 1: string}
     */
    public function status(): array
    {
        try {
            $result = Process::timeout(30)->run($this->command('status'));
        } catch (\Throwable $e) {
            return [null, ''];
        }

        $output = $result->output() . $result->errorOutput();

        if ($this->usesSystemd()) {
            // is-active exits non-zero for any state but "active", so the
            // printed state is the answer, not the exit code.
            $state = trim(strtok($result->output(), "\n") ?: '');

            return [$state !== '' ? $state : null, $output . $this->journal()];
        }

        if ($result->failed()) {
            return [null, $output . $this->failureHint($result)];
        }

        $state = trim(strtok($output, "\n") ?: '');

        return [$state !== '' ? $state : null, $output];
    }

    /**
     * A plain explanation for a failed call, or '' when the exit code came
     * from the install script or systemctl itself.
     */
    public function failureHint(ProcessResult $result): string
    {
        if ($this->usesSystemd()) {
            return $this->systemdFailureHint($result);
        }

        $fix = 'Run as root: php artisan rconfig:vector-hub-install-helper, then restart Horizon.';

        if (str_starts_with(ltrim($result->errorOutput()), 'sudo:')) {
            return "\n[hint] The Vector Hub helper is not installed, or the web user may not run it. {$fix}";
        }

        return match ($result->exitCode()) {
            64 => "\n[hint] The Vector Hub helper rejected the request (unsupported action or option).",
            65 => "\n[hint] A file named in the install options is not a file the web server user can read.",
            77 => "\n[hint] The Vector Hub helper did not run as root. {$fix}",
            78 => "\n[hint] The Vector Hub install script is missing or not owned by root. {$fix}",
            default => '',
        };
    }

    private function systemdFailureHint(ProcessResult $result): string
    {
        $error = $result->errorOutput();

        if (str_contains($error, 'Interactive authentication required') || str_contains($error, 'Access denied')) {
            return "\n[hint] systemd refused the web user. The rConfig polkit rule is missing, or this server's polkit is older than 0.106. Run as root: php artisan rconfig:bosun-units --install.";
        }

        if (str_contains($error, 'not found') || str_contains($error, 'not loaded')) {
            return "\n[hint] {$this->unit()} is not installed. Run as root: " . $this->rootCommand('install');
        }

        return '';
    }

    /** The recent journal, or '' when the web user may not read it. */
    private function journal(): string
    {
        try {
            $result = Process::timeout(15)->run(['journalctl', '-u', $this->unit(), '-n', '100', '--no-pager']);
        } catch (\Throwable $e) {
            return '';
        }

        return $result->successful() ? "\n" . $result->output() : '';
    }

    protected function unitInstalled(): bool
    {
        $path = '/etc/systemd/system/' . $this->unit();
        clearstatcache(true, $path);

        return is_file($path);
    }

    protected function bosunUnitsInstalled(): bool
    {
        clearstatcache(true, self::BOSUN_HORIZON_UNIT);

        return is_file(self::BOSUN_HORIZON_UNIT);
    }
}
