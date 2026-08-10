<?php

namespace Rconfig\VectorServer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rconfig\VectorServer\Models\VectorHubInstall;

/**
 * Self-heal for the Laravel-side hub client certificates (RCO-744 Phase 2).
 *
 * The mTLS client cert/key + CA that Laravel presents to the hub API live
 * under storage/app/vector-hub because apache cannot read the root-owned
 * /etc/vector-hub/tls. If those copies go missing (a storage reset, a partial
 * uninstall), every Laravel->hub call fails the TLS handshake and the UI shows
 * a misleading "not responding". This job re-copies them from the hub's own
 * tls dir. It runs under the root Horizon worker — apache can't read the source.
 */
class SyncHubCertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;

    public const DEFAULT_TLS_DIR = '/etc/vector-hub/tls';

    /** hub source basename => [config key for the destination path, mode] — mirrors install-hub.sh */
    private const MAP = [
        'ca.crt' => ['vector-server.hub.ca_cert', 0644],
        'laravel-client.crt' => ['vector-server.hub.client_cert', 0640],
        'laravel-client.key' => ['vector-server.hub.client_key', 0640],
    ];

    public function __construct(public int $recordId) {}

    public function handle(): void
    {
        $record = VectorHubInstall::find($this->recordId);
        if (! $record) {
            return;
        }
        $record->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        try {
            $sourceDir = rtrim((string) config('vector-server.hub.tls_source_dir', self::DEFAULT_TLS_DIR), '/');

            // Match the web user so php-fpm/apache can read the copies.
            $owner = fileowner(base_path('.env'));
            $group = filegroup(base_path('.env'));

            $log = [];
            foreach (self::MAP as $src => [$configKey, $mode]) {
                $to = (string) config($configKey);
                if ($to === '') {
                    throw new \RuntimeException("hub client cert path not configured ({$configKey}) — is the hub installed?");
                }
                $from = $sourceDir . '/' . $src;
                if (! is_file($from)) {
                    throw new \RuntimeException("source cert missing: {$from} (is the hub installed?)");
                }

                $destDir = dirname($to);
                if (! is_dir($destDir)) {
                    mkdir($destDir, 0750, true);
                }
                if (! copy($from, $to)) {
                    throw new \RuntimeException("failed to copy {$from} -> {$to}");
                }
                chmod($to, $mode);
                if ($owner !== false) {
                    @chown($to, $owner);
                }
                if ($group !== false) {
                    @chgrp($to, $group);
                }
                $log[] = 'restored ' . basename($to);
            }

            $record->update([
                'status' => 'success',
                'exit_code' => 0,
                'output' => implode("\n", $log),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'output' => '[resync error] ' . $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
