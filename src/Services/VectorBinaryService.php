<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Rconfig\VectorServer\Models\VectorBinary;
use Rconfig\VectorServer\Models\VectorBinaryCache;

class VectorBinaryService
{
    public const DOWNLOAD_INDEX_URL = 'https://portal.rconfig.com/api/vectoragent-downloads';

    public function getBinaryStoragePath(): string
    {
        if (function_exists('agent_binaries_path')) {
            return rtrim(agent_binaries_path(), DIRECTORY_SEPARATOR);
        }

        return storage_path('app/vector/binaries');
    }

    public function getActiveBinary(string $platform): ?VectorBinary
    {
        return VectorBinary::where('platform', $platform)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();
    }

    public function getActiveBinaryCache(VectorBinary $binary): ?VectorBinaryCache
    {
        return $binary->caches()->orderByDesc('downloaded_at')->first();
    }

    public function activateBinary(VectorBinary $binary): void
    {
        DB::transaction(function () use ($binary) {
            VectorBinary::where('platform', $binary->platform)
                ->where('id', '!=', $binary->id)
                ->update(['is_active' => false]);

            $binary->is_active = true;
            $binary->save();
        });
    }


    public function resolveLatestDisplayVersion(VectorBinary $binary): ?string
    {
        if ($binary->version !== 'latest') {
            return null;
        }

        try {
            $response = Http::timeout(20)->get(self::DOWNLOAD_INDEX_URL);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $payload = $response->json();
        $downloads = $payload['data'] ?? null;
        if (! is_array($downloads)) {
            return null;
        }

        $platformKey = $this->normalizePlatformKey($binary->platform);
        $targetSha = strtolower((string) $binary->sha256);

        foreach ($downloads as $versionKey => $entries) {
            if ($versionKey === 'latest') {
                continue;
            }
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $name = strtolower((string) ($entry['name'] ?? ''));
                if ($platformKey !== '' && $name !== '' && ! str_contains($name, $platformKey)) {
                    continue;
                }

                $sha = strtolower(trim((string) ($entry['sha256'] ?? '')));
                if ($sha !== '' && $sha === $targetSha) {
                    return 'latest/' . (str_starts_with((string) $versionKey, 'v') ? (string) $versionKey : 'v' . $versionKey);
                }
            }
        }

        return null;
    }

    protected function normalizePlatformKey(string $platform): string
    {
        $normalized = strtolower($platform);
        if (str_contains($normalized, 'win')) {
            return 'windows';
        }

        return 'linux';
    }

    public function getDownloadUrlForPlatform(string $platform, string $version): string
    {
        $version = $version === '' ? 'latest' : $version;

        $map = [
            'linux_amd64' => "https://dl.rconfig.com/download-vector-agent/vectoragent-linux-{$version}",
        ];

        if (! array_key_exists($platform, $map)) {
            throw new \InvalidArgumentException("Unsupported platform: {$platform}");
        }

        return $map[$platform];
    }
}
