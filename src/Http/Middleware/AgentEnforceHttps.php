<?php

namespace Rconfig\VectorServer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AgentEnforceHttps
{
    public function handle(Request $request, Closure $next)
    {
        $isSecure = $request->isSecure();
        $forwardedProto = $request->header('X-Forwarded-Proto');

        if (!$isSecure && strtolower($forwardedProto) !== 'https') {
            $msg = 'Blocked non-HTTPS request from ' . $request->ip();
            Log::warning($msg, ['forwarded_proto' => $forwardedProto]);
            return response()->json(['error' => 'HTTPS is required for this endpoint.'], 403);
        }

        return $next($request);
    }
}
