<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rconfig\VectorServer\Models\VectorHubInstall;
use Symfony\Component\Process\Process;

/**
 * systemctl control for the vector-hub service (RCO-744 Phase 2). Runs under
 * the root Horizon worker because apache has no privilege to manage services.
 * The action is a fixed whitelist — never operator free-text.
 */
class HubServiceActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 90;

    private const ALLOWED = ['start', 'restart', 'stop'];

    public function __construct(public int $recordId, public string $action) {}

    public function handle(): void
    {
        $record = VectorHubInstall::find($this->recordId);
        if (! $record) {
            return;
        }

        if (! in_array($this->action, self::ALLOWED, true)) {
            $record->update(['status' => 'failed', 'output' => "Unsupported action: {$this->action}", 'finished_at' => now()]);

            return;
        }

        $record->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        try {
            $proc = new Process(['systemctl', $this->action, 'vector-hub']);
            $proc->setTimeout($this->timeout);
            $proc->run();

            $active = new Process(['systemctl', 'is-active', 'vector-hub']);
            $active->setTimeout(20);
            $active->run();
            $serviceStatus = trim($active->getOutput() . $active->getErrorOutput());

            $record->update([
                'status' => $proc->isSuccessful() ? 'success' : 'failed',
                'exit_code' => $proc->getExitCode(),
                'service_status' => $serviceStatus ?: null,
                'output' => "systemctl {$this->action} vector-hub\n"
                    . $proc->getOutput() . $proc->getErrorOutput()
                    . "\n--- systemctl is-active vector-hub ---\n" . $serviceStatus,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'output' => '[job error] ' . $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
