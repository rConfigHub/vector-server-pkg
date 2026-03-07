<?php

namespace App\Console\Commands\Vector;

use Illuminate\Console\Command;
use Rconfig\VectorServer\Models\VectorBinary;
use Rconfig\VectorServer\Models\VectorBinaryCache;
use Rconfig\VectorServer\Services\VectorBinaryService;

class VectorSideloadAgentBinariesCmd extends Command
{
    protected $signature = 'vector:agent:sideload-binaries {--activate : Activate sideloaded latest binaries per platform}';

    protected $description = 'Scan local agent binary directory and populate vector binary metadata tables';

    public function handle(VectorBinaryService $binaryService): int
    {
        $storageDir = rtrim($binaryService->getBinaryStoragePath(), DIRECTORY_SEPARATOR);
        if (! is_dir($storageDir)) {
            $this->error("Binary directory does not exist: {$storageDir}");
            return 1;
        }

        $files = glob($storageDir . DIRECTORY_SEPARATOR . '*') ?: [];
        $processed = 0;
        $createdBinaries = 0;
        $createdCaches = 0;
        $skipped = 0;
        $latestByPlatform = [];

        foreach ($files as $filePath) {
            if (! is_file($filePath) || filesize($filePath) === 0) {
                continue;
            }

            $parsed = $this->parseBinaryFileName(basename($filePath));
            if ($parsed === null) {
                $skipped++;
                $this->warn('Skipping unrecognized file: ' . basename($filePath));
                continue;
            }

            $sha256 = hash_file('sha256', $filePath);
            if ($sha256 === false) {
                $skipped++;
                $this->warn('Skipping unreadable file: ' . basename($filePath));
                continue;
            }

            $sizeBytes = filesize($filePath) ?: null;

            $binary = VectorBinary::where('platform', $parsed['platform'])
                ->where('version', $parsed['version'])
                ->where('sha256', $sha256)
                ->first();

            if (! $binary) {
                $binary = VectorBinary::create([
                    'platform' => $parsed['platform'],
                    'version' => $parsed['version'],
                    'sha256' => $sha256,
                    'size_bytes' => $sizeBytes,
                    'is_active' => 0,
                    'created_at' => now(),
                ]);
                $createdBinaries++;
            } elseif ($binary->size_bytes === null && $sizeBytes !== null) {
                $binary->size_bytes = $sizeBytes;
                $binary->save();
            }

            $cacheExists = VectorBinaryCache::where('binary_id', $binary->id)
                ->where('local_path', $filePath)
                ->exists();

            if (! $cacheExists) {
                VectorBinaryCache::create([
                    'binary_id' => $binary->id,
                    'local_path' => $filePath,
                    'downloaded_at' => now(),
                    'verified_at' => now(),
                ]);
                $createdCaches++;
            }

            if ($parsed['version'] === 'latest') {
                $latestByPlatform[$parsed['platform']] = $binary;
            }

            $processed++;
        }

        if ((bool) $this->option('activate')) {
            foreach ($latestByPlatform as $platform => $binary) {
                $binaryService->activateBinary($binary);
                $this->info("Activated latest binary for {$platform}");
            }
        }

        $this->line("Scanned directory: {$storageDir}");
        $this->line("Processed: {$processed}, binaries created: {$createdBinaries}, caches created: {$createdCaches}, skipped: {$skipped}");

        return 0;
    }

    protected function parseBinaryFileName(string $fileName): ?array
    {
        if (! preg_match('/^vectoragent-(linux|windows)-([A-Za-z0-9._-]+?)(?:\\.exe)?$/i', $fileName, $matches)) {
            return null;
        }

        $platform = strtolower($matches[1]) === 'windows' ? 'windows_amd64' : 'linux_amd64';
        $version = strtolower($matches[2]) === 'latest'
            ? 'latest'
            : ltrim($matches[2], 'vV');

        if ($version === '') {
            return null;
        }

        return [
            'platform' => $platform,
            'version' => $version,
        ];
    }
}
