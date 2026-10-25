<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Rconfig\VectorServer\Models\VectorHubInstall;
use Rconfig\VectorServer\Services\VectorHubCtl;

/**
 * Installs or removes Vector Hub on the rConfig server (RCO-744 Phase 2,
 * Stage B; RCO-1794). Horizon runs as the web user, so the job calls the
 * root-owned wrapper through `sudo -n`. The wrapper runs its own root-owned
 * copy of install-hub.sh; nothing in the app folder is ever run as root.
 *
 * Safety: the operator-supplied flags arrive as a pre-validated argv list from
 * the controller and are passed as discrete arguments (no shell). The wrapper
 * checks every flag again and rejects anything else.
 */
class InstallVectorHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bounded so a hung install can't pin a worker forever. */
    public $timeout = 600;

    /** @var array<int,string> */
    public const ACTIONS = ['install', 'uninstall'];

    /**
     * @param  array<int,string>  $args  validated install flag argv (ignored for uninstall)
     */
    public function __construct(public int $installId, public array $args, public string $action = 'install') {}

    public function handle(VectorHubCtl $ctl): void
    {
        $install = VectorHubInstall::find($this->installId);
        if (! $install) {
            return;
        }

        if (! in_array($this->action, self::ACTIONS, true)) {
            $install->update(['status' => 'failed', 'output' => "Unsupported action: {$this->action}", 'finished_at' => now()]);

            return;
        }

        $install->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        try {
            // Live progress for the UI poll, saved at most every 1.5s.
            $lastSave = microtime(true);
            $flags = $this->action === 'install' ? $this->args : [];
            $result = Process::timeout($this->timeout)->run(
                $ctl->command($this->action, $flags),
                function (string $type, string $chunk) use ($install, &$lastSave) {
                    $install->output = (string) $install->output . $chunk;
                    if (microtime(true) - $lastSave > 1.5) {
                        $install->save();
                        $lastSave = microtime(true);
                    }
                }
            );

            [$serviceStatus, $statusOutput] = $ctl->status();

            // The result holds the full output; the streamed copy was progress only.
            $install->update([
                'status' => $result->successful() ? 'success' : 'failed',
                'exit_code' => $result->exitCode(),
                'service_status' => $serviceStatus,
                'output' => $result->output() . $result->errorOutput() . $ctl->failureHint($result)
                    . "\n\n--- systemctl is-active vector-hub ---\n" . $statusOutput,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $install->update([
                'status' => 'failed',
                'output' => (string) optional($install->fresh())->output . "\n[job error] " . $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
