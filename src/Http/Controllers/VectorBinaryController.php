<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Rconfig\VectorServer\Models\VectorBinary;
use Rconfig\VectorServer\Services\VectorBinaryService;

class VectorBinaryController extends Controller
{

    public function index(Request $request, VectorBinaryService $binaryService)
    {
        $platform = (string) $request->query('platform', 'linux_amd64');

        $binaries = VectorBinary::where('platform', $platform)
            ->orderByDesc('created_at')
            ->get();

        $items = $binaries->map(function (VectorBinary $binary) use ($binaryService) {
            $displayVersion = $binary->version;
            if ($binary->version === 'latest') {
                $resolved = $binaryService->resolveLatestDisplayVersion($binary);
                if ($resolved) {
                    $displayVersion = $resolved;
                }
            }

            $cache = $binary->caches()->orderByDesc('downloaded_at')->first();

            return [
                'platform' => $binary->platform,
                'version' => $displayVersion,
                'raw_version' => $binary->version,
                'sha256' => $binary->sha256,
                'size_bytes' => $binary->size_bytes,
                'is_active' => (bool) $binary->is_active,
                'downloaded_at' => $cache?->downloaded_at,
                'updated_at' => $binary->updated_at ?? $binary->created_at,
            ];
        });

        return response()->json([
            'platform' => $platform,
            'items' => $items,
        ]);
    }
    public function active(Request $request, VectorBinaryService $binaryService)
    {
        $platform = (string) $request->query('platform', 'linux_amd64');

        $binary = $binaryService->getActiveBinary($platform);
        if (! $binary) {
            return response()->json(['error' => 'No active binary available.'], 404);
        }

        $displayVersion = $binary->version;
        if ($binary->version === 'latest') {
            $resolved = $binaryService->resolveLatestDisplayVersion($binary);
            if ($resolved) {
                $displayVersion = $resolved;
            }
        }

        return response()->json([
            'platform' => $binary->platform,
            'version' => $displayVersion,
            'raw_version' => $binary->version,
            'sha256' => $binary->sha256,
            'size_bytes' => $binary->size_bytes,
            'updated_at' => $binary->updated_at ?? $binary->created_at,
        ]);
    }

    public function download(Request $request)
    {
        $payload = $request->validate([
            'platform' => 'required|string',
            'version' => 'nullable|string',
        ]);

        $platform = $payload['platform'];
        $version = $payload['version'] ?? 'latest';

        $exitCode = Artisan::call('vector:agent:download-binary', [
            'platform' => $platform,
            'version' => $version,
        ]);

        if ($exitCode !== 0) {
            return response()->json([
                'error' => 'Failed to download binary.',
                'output' => Artisan::output(),
            ], 422);
        }

        return response()->json([
            'message' => 'Download completed.',
            'platform' => $platform,
            'version' => $version,
        ]);
    }

    public function activate(Request $request, VectorBinaryService $binaryService)
    {
        $payload = $request->validate([
            'platform' => 'required|string',
            'version' => 'required|string',
        ]);

        $binary = VectorBinary::where('platform', $payload['platform'])
            ->where('version', $payload['version'])
            ->orderByDesc('created_at')
            ->first();

        if (! $binary) {
            return response()->json(['error' => 'Binary not found.'], 404);
        }

        $binaryService->activateBinary($binary);

        return response()->json([
            'message' => 'Binary activated.',
            'platform' => $binary->platform,
            'version' => $binary->version,
            'sha256' => $binary->sha256,
            'size_bytes' => $binary->size_bytes,
            'updated_at' => $binary->updated_at ?? $binary->created_at,
        ]);
    }

    public function delete(Request $request)
    {
        $payload = $request->validate([
            'platform' => 'required|string',
            'version' => 'required|string',
        ]);

        $binary = VectorBinary::where('platform', $payload['platform'])
            ->where('version', $payload['version'])
            ->orderByDesc('created_at')
            ->first();

        if (! $binary) {
            return response()->json(['error' => 'Binary not found.'], 404);
        }

        $binary->load('caches');
        foreach ($binary->caches as $cache) {
            $path = (string) $cache->local_path;
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        $binary->delete();

        return response()->json([
            'message' => 'Binary deleted.',
            'platform' => $payload['platform'],
            'version' => $payload['version'],
        ]);
    }
}
