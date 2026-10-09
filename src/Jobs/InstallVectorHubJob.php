<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\View;
use Rconfig\VectorServer\Models\VectorHubInstall;
use Symfony\Component\Process\Process;

/**
 * Runs install-hub.sh on the rConfig server (RCO-744 Phase 2, Stage B). This
 * job executes under the Horizon worker, which runs as root — the web tier
 * (apache) has no privilege to install a system service itself.
 *
 * Safety: the operator-supplied flags arrive as a pre-validated argv list from
 * the controller and are passed to Symfony Process as discrete arguments (no
 * shell), so there is no shell-injection surface here.
 */
class InstallVectorHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bounded so a hung install can't pin a worker forever. */
    public $timeout = 600;

    /** @param array<int,string> $args validated install-hub.sh flag argv */
    public function __construct(public int $installId, public array $args) {}

    public function handle(): void
    {
        $install = VectorHubInstall::find($this->installId);
        if (! $install) {
            return;
        }

        $install->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        $scriptPath = null;
        try {
            $scriptPath = $this->renderScript();

            $buffer = '';
            $lastFlush = microtime(true);
            $flush = function (bool $force = false) use (&$buffer, &$lastFlush, $install) {
                if ($buffer === '') {
                    return;
                }
                if ($force || microtime(true) - $lastFlush > 1.5) {
                    $install->output = (string) $install->output . $buffer;
                    $install->save();
                    $buffer = '';
                    $lastFlush = microtime(true);
                }
            };

            $process = new Process(array_merge(['/bin/bash', $scriptPath], $this->args));
            $process->setTimeout($this->timeout);
            $process->run(function ($type, $chunk) use (&$buffer, $flush) {
                $buffer .= $chunk;
                $flush();
            });
            $flush(true);

            $serviceStatus = $this->capture(['systemctl', 'is-active', 'vector-hub']);
            $journal = $this->capture(['journalctl', '-u', 'vector-hub', '-n', '100', '--no-pager']);

            $install->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'exit_code' => $process->getExitCode(),
                'service_status' => trim($serviceStatus) ?: null,
                'output' => (string) $install->fresh()->output
                    . "\n\n--- systemctl is-active vector-hub ---\n" . $serviceStatus
                    . "\n--- journalctl -u vector-hub -n 100 ---\n" . $journal,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $install->update([
                'status' => 'failed',
                'output' => (string) optional($install->fresh())->output . "\n[job error] " . $e->getMessage(),
                'finished_at' => now(),
            ]);
        } finally {
            if ($scriptPath && is_file($scriptPath)) {
                @unlink($scriptPath);
            }
        }
    }

    /**
     * Render the install-hub blade to a temp shell script and return its path.
     * Mirrors VectorInstallScriptController's namespace/fallback resolution.
     */
    private function renderScript(): string
    {
        $namespacePath = resource_path('views/vendor/vector-server');
        if (is_dir($namespacePath)) {
            View::addNamespace('vector-server', $namespacePath);
        }

        $viewName = 'vector-server::vector.install-hub.sh';
        if (View::exists($viewName)) {
            $contents = View::make($viewName)->render();
        } else {
            $file = resource_path('views/vendor/vector-server/vector/install-hub.sh.blade.php');
            if (! is_file($file)) {
                throw new \RuntimeException('install-hub script view is missing.');
            }
            $contents = View::file($file)->render();
        }

        $dir = storage_path('app/vector-hub');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $path = $dir . '/install-run-' . $this->installId . '.sh';
        file_put_contents($path, $contents);
        @chmod($path, 0700);

        return $path;
    }

    /** Run a read-only command, returning combined output (empty on failure). */
    private function capture(array $cmd): string
    {
        try {
            $p = new Process($cmd);
            $p->setTimeout(20);
            $p->run();

            return $p->getOutput() . $p->getErrorOutput();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
