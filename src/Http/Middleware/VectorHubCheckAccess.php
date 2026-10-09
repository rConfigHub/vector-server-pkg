<?php

namespace Rconfig\VectorServer\Http\Middleware;

use App\Traits\RespondsWithHttpStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Guards the api/vector-hub/* routes: only the Vector Hub process may call
 * them, proven by the shared secret the install script writes into both the
 * hub's environment and vector-server.hub.auth_secret.
 */
class VectorHubCheckAccess
{
    use RespondsWithHttpStatus;

    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        $secret = config('vector-server.hub.auth_secret');
        if (empty($secret)) {
            Log::warning('Vector Hub callback rejected: hub.auth_secret not configured', ['ip' => $request->ip()]);

            return $this->failureResponse('Vector Hub integration is not configured.', 503);
        }

        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals($secret, $provided)) {
            Log::warning('Vector Hub callback rejected: bad shared secret', ['ip' => $request->ip()]);

            return $this->failureResponse('Unauthorized.', 401);
        }

        return $next($request);
    }
}
