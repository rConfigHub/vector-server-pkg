<?php

namespace Rconfig\VectorServer\Http\Middleware;

use App\Facades\VectorServer;
use Closure;
use Illuminate\Http\Request;

class AgentAttachId
{
    public function handle(Request $request, Closure $next)
    {
        if (!VectorServer::isInstalled()) {
            return $next($request);
        }

        if ($request->hasHeader('X-Agent-ID')) {
            $agentId = $request->header('X-Agent-ID');
            // Store agent ID globally so that it's available in the app
            app()->instance('agent_id', (int) $agentId);
        }

        if (!$request->hasHeader('X-Agent-ID')) {
            $user = auth()->user();

            if ($user) {
                $agentId = (int) $user->active_agent_id;
                app()->instance('agent_id', $agentId);
            }
        }

        return $next($request);
    }
}
