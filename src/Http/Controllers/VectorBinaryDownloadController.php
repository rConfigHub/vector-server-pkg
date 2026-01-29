<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Rconfig\VectorServer\Services\VectorBinaryService;

class VectorBinaryDownloadController extends Controller
{
    public function latest(Request $request, VectorBinaryService $binaryService)
    {
        $platform = (string) $request->query('platform', 'linux_amd64');
        $binary = $binaryService->getActiveBinary($platform);
        if (! $binary) {
            return response('No active binary available.', 404);
        }

        $cache = $binaryService->getActiveBinaryCache($binary);
        if (! $cache || ! is_file($cache->local_path)) {
            return response('Active binary cache is missing.', 404);
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'X-Vector-Binary-Version' => $binary->version,
            'X-Vector-Binary-Sha256' => $binary->sha256,
        ];

                $fileName = str_contains(strtolower($platform), 'win') ? 'vectoragent-latest.exe' : 'vectoragent-latest';

        return response()->download($cache->local_path, $fileName, $headers);
    }

    public function latestSha(Request $request, VectorBinaryService $binaryService)
    {
        $platform = (string) $request->query('platform', 'linux_amd64');
        $binary = $binaryService->getActiveBinary($platform);
        if (! $binary) {
            return response('No active binary available.', 404);
        }

        $cache = $binaryService->getActiveBinaryCache($binary);
        if (! $cache || ! is_file($cache->local_path)) {
            return response('Active binary cache is missing.', 404);
        }

        $fileName = str_contains(strtolower($platform), 'win') ? 'vectoragent-latest.exe' : 'vectoragent-latest';

        $body = $binary->sha256 . '  ' . $fileName . "\n";

        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
