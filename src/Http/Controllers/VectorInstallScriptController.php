<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Rconfig\VectorServer\Services\BootstrapTokenService;
use Rconfig\VectorServer\Services\VectorBinaryService;

class VectorInstallScriptController extends Controller
{
    public function install(Request $request, BootstrapTokenService $tokenService, VectorBinaryService $binaryService)
    {
        $rawToken = (string) $request->query('bootstrap_token', '');

        if ($rawToken === '') {
            return response('Missing bootstrap token.', 400);
        }

        $token = $tokenService->validate($rawToken);
        if (! $token) {
            return response('Invalid or expired bootstrap token.', 403);
        }

        $agent = $token->agent;
        if (! $agent) {
            return response('Agent not found for bootstrap token.', 404);
        }

        $binary = $binaryService->getActiveBinary('linux_amd64');
        if (! $binary) {
            return response('No active Linux binary available.', 404);
        }

        $cache = $binaryService->getActiveBinaryCache($binary);
        if (! $cache || ! is_file($cache->local_path)) {
            return response('Active binary cache is missing.', 404);
        }

        $serverUrl = $request->getSchemeAndHttpHost();

        $namespacePath = resource_path('views/vendor/vector-server');
        if (is_dir($namespacePath)) {
            view()->addNamespace('vector-server', $namespacePath);
        }

        $viewName = 'vector-server::vector.install.sh';
        if (! view()->exists($viewName)) {
            $viewName = view()->exists('vector.install.sh') ? 'vector.install.sh' : null;
        }

        $viewData = [
            'agent' => $agent,
            'bootstrapToken' => $rawToken,
            'binary' => $binary,
            'binaryCache' => $cache,
            'serverUrl' => $serverUrl,
        ];

        if ($viewName !== null) {
            $response = response()->view($viewName, $viewData);
            $response->headers->set('Content-Type', 'text/x-sh', true);
            return $response;
        }

        $fallbackPaths = [
            resource_path('views/vendor/vector-server/vector/install.sh.blade.php'),
            resource_path('views/vector/install.sh.blade.php'),
        ];

        foreach ($fallbackPaths as $path) {
            if (is_file($path)) {
                $response = response()->make(view()->file($path, $viewData));
                $response->headers->set('Content-Type', 'text/x-sh', true);
                return $response;
            }
        }

        return response('Install script view is missing.', 500);
    }
}
