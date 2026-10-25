<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Calls the root-owned Vector Hub wrapper through `sudo -n` (RCO-1794).
 *
 * Horizon runs as the web user, which cannot install a system service or run
 * systemctl. rConfig's `rconfig:vector-hub-install-helper` installs the wrapper
 * and a sudoers rule naming only it. The wrapper accepts fixed actions and
 * checks every install flag itself; nothing here builds a shell string.
 */
class VectorHubCtl
{
    public const DEFAULT_PATH = '/usr/local/libexec/rconfig/rconfig-vector-hub';

    /** @var array<int,string> */
    public const ACTIONS = ['install', 'uninstall', 'start', 'stop', 'restart', 'status'];

    public function path(): string
    {
        return (string) (config('vector-server.hub.ctl_path') ?: self::DEFAULT_PATH);
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

        return array_merge(['sudo', '-n', $this->path(), $action], array_values($flags));
    }

    /**
     * The service state (first line of the wrapper's status output) and the
     * full status output with the recent journal. State is null when the
     * wrapper could not be run.
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

        if ($result->failed()) {
            return [null, $output . $this->failureHint($result)];
        }

        $state = trim(strtok($output, "\n") ?: '');

        return [$state !== '' ? $state : null, $output];
    }

    /**
     * A plain explanation for a failed wrapper call, or '' when the exit code
     * came from the install script or systemctl.
     */
    public function failureHint(ProcessResult $result): string
    {
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
}
