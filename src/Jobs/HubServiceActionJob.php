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
 * systemctl control for the vector-hub service (RCO-744 Phase 2; RCO-1794).
 * Horizon runs as the web user, so the job calls the root-owned wrapper
 * through `sudo -n`. The action is a fixed whitelist, never operator free-text,
 * and the wrapper checks it again.
 */
class HubServiceActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 90;

    private const ALLOWED = ['start', 'restart', 'stop'];

    public function __construct(public int $recordId, public string $action) {}

    public function handle(VectorHubCtl $ctl): void
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
            $result = Process::timeout($this->timeout)->run($ctl->command($this->action));

            [$serviceStatus] = $ctl->status();

            $record->update([
                'status' => $result->successful() ? 'success' : 'failed',
                'exit_code' => $result->exitCode(),
                'service_status' => $serviceStatus,
                'output' => "systemctl {$this->action} vector-hub\n"
                    . $result->output() . $result->errorOutput() . $ctl->failureHint($result)
                    . "\n--- systemctl is-active vector-hub ---\n" . ($serviceStatus ?? 'unknown'),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'output' => '[job error] ' . $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
