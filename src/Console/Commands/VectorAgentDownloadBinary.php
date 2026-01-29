<?php

namespace Rconfig\VectorServer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Rconfig\VectorServer\Models\VectorBinary;
use Rconfig\VectorServer\Models\VectorBinaryCache;
use Rconfig\VectorServer\Services\VectorBinaryService;

class VectorAgentDownloadBinary extends Command
{
    protected $signature = 'vector:agent:download-binary {platform?} {version?} {--debug}';
    protected $description = 'Download and cache Vector agent binaries for distribution';
    protected const DOWNLOAD_INDEX_URL = 'https://portal.rconfig.com/api/vectoragent-downloads';

    public function handle(VectorBinaryService $binaryService)
    {
        $platformArg = (string) ($this->argument('platform') ?? '');
        $version = (string) ($this->argument('version') ?? 'latest');
        $platforms = $platformArg !== '' ? [$platformArg] : ['linux_amd64', 'windows_amd64'];
        $debug = (bool) $this->option('debug');

        $downloads = $this->fetchDownloadIndex($debug);
        if (! $downloads) {
            $this->printOfflineHelp($binaryService, $platforms, $version);
            return 1;
        }

        $exitCode = 0;
        foreach ($platforms as $platform) {
            $result = $this->downloadBinary($binaryService, $downloads, $platform, $version, $debug);
            if ($result !== 0) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    protected function downloadBinary(VectorBinaryService $binaryService, array $downloads, string $platform, string $version, bool $debug): int
    {
        $versionKey = $this->normalizeVersionKey($version);
        $platformKey = $this->normalizePlatformKey($platform);

        $entry = $this->resolveDownloadEntry($downloads, $versionKey, $platformKey);
        if (! $entry) {
            $this->error("No download found for platform '{$platform}' and version '{$versionKey}'.");
            if ($debug) {
                $this->line('Available versions: ' . implode(', ', array_keys($downloads)));
            }
            return 1;
        }

        $downloadUrl = (string) ($entry['url'] ?? '');
        if ($downloadUrl === '') {
            $this->error("Download URL missing for platform '{$platform}' and version '{$versionKey}'.");
            if ($debug) {
                $this->line('Entry: ' . json_encode($entry));
            }
            return 1;
        }

        $fileName = $this->resolveFileName($entry, $downloadUrl, $platformKey, $versionKey);
        $this->info("Downloading Vector agent binary from {$downloadUrl}");

        $storageDir = $binaryService->getBinaryStoragePath();
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0755, true) && ! is_dir($storageDir)) {
            $this->error('Failed to create binary storage directory.');
            if ($debug) {
                $this->line('Storage directory: ' . $storageDir);
                $this->line('Last error: ' . json_encode(error_get_last()));
            }
            return 1;
        }

        $filePath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

        try {
            $response = Http::timeout(120)->sink($filePath)->get($downloadUrl);
        } catch (\Throwable $e) {
            @unlink($filePath);
            $this->error('Download failed: ' . $e->getMessage());
            if ($debug) {
                $this->line('Download URL: ' . $downloadUrl);
                $this->line('Target path: ' . $filePath);
                $this->line('Exception: ' . get_class($e));
            }
            return 1;
        }

        if (! $response->ok()) {
            @unlink($filePath);
            $this->error('Download failed with status ' . $response->status() . '.');
            if ($debug) {
                $this->line('Download URL: ' . $downloadUrl);
                $this->line('Target path: ' . $filePath);
                $this->line('Response body: ' . substr((string) $response->body(), 0, 500));
            }
            return 1;
        }

        if (! is_file($filePath) || filesize($filePath) === 0) {
            @unlink($filePath);
            $this->error('Downloaded file is missing or empty.');
            if ($debug) {
                $this->line('Target path: ' . $filePath);
            }
            return 1;
        }

        $this->info('Computing SHA256 checksum...');
        $sha256 = hash_file('sha256', $filePath);
        if ($sha256 === false) {
            @unlink($filePath);
            $this->error('Failed to compute SHA256 checksum.');
            if ($debug) {
                $this->line('Target path: ' . $filePath);
                $this->line('Last error: ' . json_encode(error_get_last()));
            }
            return 1;
        }

        $expectedSha256 = $this->normalizeHashValue($entry['sha256'] ?? null);
        if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $sha256)) {
            @unlink($filePath);
            $this->error('SHA256 checksum mismatch for downloaded binary.');
            if ($debug) {
                $this->line('Expected: ' . $expectedSha256);
                $this->line('Actual: ' . $sha256);
            }
            return 1;
        }

        $sizeBytes = filesize($filePath) ?: null;

        $existingBinary = VectorBinary::where('platform', $platform)
            ->where('version', $versionKey)
            ->where('sha256', $sha256)
            ->first();

        $binary = $existingBinary ?: VectorBinary::create([
            'platform' => $platform,
            'version' => $versionKey,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'is_active' => false,
            'created_at' => now(),
        ]);

        if ($binary->size_bytes === null && $sizeBytes !== null) {
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
        }

        if ($version === 'latest') {
            $binaryService->activateBinary($binary);
            $this->info('Marked binary as active for platform ' . $platform . '.');
        }

        $this->info('Binary cached successfully.');
        $this->line('SHA256: ' . $sha256);

        return 0;
    }

    protected function fetchDownloadIndex(bool $debug): ?array
    {
        try {
            $response = Http::timeout(30)->get(self::DOWNLOAD_INDEX_URL);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch download index: ' . $e->getMessage());
            if ($debug) {
                $this->line('URL: ' . self::DOWNLOAD_INDEX_URL);
                $this->line('Exception: ' . get_class($e));
            }
            return null;
        }

        if (! $response->ok()) {
            $this->error('Download index returned status ' . $response->status() . '.');
            if ($debug) {
                $this->line('URL: ' . self::DOWNLOAD_INDEX_URL);
                $this->line('Response body: ' . substr((string) $response->body(), 0, 500));
            }
            return null;
        }

        $payload = $response->json();
        $downloads = $payload['data'] ?? null;
        if (! is_array($downloads)) {
            $this->error('Download index payload is malformed.');
            if ($debug) {
                $this->line('Payload: ' . json_encode($payload));
            }
            return null;
        }

        return $downloads;
    }

    protected function printOfflineHelp(VectorBinaryService $binaryService, array $platforms, string $version): void
    {
        $storageDir = $binaryService->getBinaryStoragePath();
        $normalizedVersion = $this->normalizeVersionKey($version);

        $this->warn('Could not reach the Vector agent download index.');
        $this->line('');
        $this->line('How to download manually:');
        $this->line('1) On a machine with working internet, open the download index:');
        $this->line('   ' . self::DOWNLOAD_INDEX_URL);
        $this->line('2) Download the required binary for your platform and version.');
        $this->line('3) Upload the downloaded file(s) to this server at:');
        $this->line('   ' . $storageDir);
        $this->line('');
        $this->line('Notes:');
        $this->line('- Filenames should match the download URL basename (example: vectoragent-linux-latest or vectoragent-windows-latest.exe).');
        $this->line('- Platforms requested: ' . implode(', ', $platforms) . '. Version requested: ' . $normalizedVersion . '.');
    }

    protected function normalizeVersionKey(string $version): string
    {
        if ($version === '' || strtolower($version) === 'latest') {
            return 'latest';
        }

        return ltrim($version, "vV");
    }

    protected function normalizePlatformKey(string $platform): string
    {
        $normalized = strtolower($platform);
        if (str_contains($normalized, 'win')) {
            return 'windows';
        }

        return 'linux';
    }

    protected function resolveDownloadEntry(array $downloads, string $versionKey, string $platformKey): ?array
    {
        $entries = $downloads[$versionKey] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = strtolower((string) ($entry['name'] ?? ''));
            if ($name !== '' && str_contains($name, $platformKey)) {
                return $entry;
            }
        }

        return null;
    }

    protected function resolveFileName(array $entry, string $downloadUrl, string $platformKey, string $versionKey): string
    {
        $path = parse_url($downloadUrl, PHP_URL_PATH) ?: '';
        $basename = $path !== '' ? basename($path) : '';

        if ($basename !== '') {
            return $basename;
        }

        $entryName = (string) ($entry['name'] ?? '');
        if ($entryName !== '') {
            return $entryName;
        }

        $suffix = $platformKey === 'windows' ? '.exe' : '';

        return "vectoragent-{$platformKey}-{$versionKey}{$suffix}";
    }

    protected function normalizeHashValue(?string $hash): ?string
    {
        $hash = $hash !== null ? trim($hash) : '';
        if ($hash === '' || strtolower($hash) === 'null') {
            return null;
        }

        return strtolower($hash);
    }
}
