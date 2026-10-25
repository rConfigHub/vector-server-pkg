<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Support\Facades\Process;

/**
 * Whether the root-owned Vector Hub helper is in place, before any action is
 * queued (RCO-1785). The wrapper and its install script must exist, be owned
 * by root and be writable by root only. The sudoers file sits in a folder the
 * web user cannot read, so the rule is checked by asking sudo itself with
 * `sudo -n -l`, which exits 0 only when the web user may run the wrapper
 * without a password.
 */
class VectorHubHelperCheck
{
    public const FIX = 'On the server, run: sudo php artisan rconfig:vector-hub-install-helper';

    private const DEFAULT_INSTALL_SCRIPT = '/usr/local/libexec/rconfig/rconfig-vector-hub-install.sh';

    public function __construct(private readonly VectorHubCtl $ctl) {}

    public function isInstalled(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What is wrong with the helper, in plain words. Empty when it is ready.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $wrapper = $this->ctl->path();
        $script = (string) (config('vector-server.hub.install_script_path') ?: self::DEFAULT_INSTALL_SCRIPT);

        $problems = [
            ...$this->rootOwnedFileProblems($wrapper, 'helper'),
            ...$this->rootOwnedFileProblems($script, 'install script'),
        ];

        if ($problems === [] && ! $this->sudoAllows($wrapper)) {
            $problems[] = "sudo does not let the web user run {$wrapper} without a password, so the sudoers rule is missing or wrong.";
        }

        return $problems;
    }

    /**
     * The 422 message for an action that needs the helper.
     *
     * @param  list<string>  $problems
     */
    public static function failureMessage(array $problems): string
    {
        return 'The Vector Hub helper is not installed: ' . implode(' ', $problems) . ' ' . self::FIX;
    }

    /**
     * @return list<string>
     */
    private function rootOwnedFileProblems(string $path, string $name): array
    {
        if (! $this->isFile($path)) {
            return ["The {$name} {$path} is missing."];
        }

        $problems = [];

        if ($this->ownerId($path) !== 0) {
            $problems[] = "The {$name} {$path} is not owned by root.";
        }

        $mode = $this->mode($path);

        if ($mode === null || ($mode & 0022) !== 0) {
            $problems[] = "The {$name} {$path} can be changed by users other than root.";
        }

        return $problems;
    }

    private function sudoAllows(string $wrapper): bool
    {
        try {
            return Process::timeout(5)->run(['sudo', '-n', '-l', $wrapper, 'status'])->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isFile(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path);
    }

    protected function ownerId(string $path): ?int
    {
        $owner = @fileowner($path);

        return $owner === false ? null : $owner;
    }

    protected function mode(string $path): ?int
    {
        $perms = @fileperms($path);

        return $perms === false ? null : $perms & 07777;
    }
}
