<?php

namespace Rconfig\VectorServer\Http\Middleware;

use App\Traits\RespondsWithHttpStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Models\Agent;

class AgentCheckApiSyncAccess
{
    use RespondsWithHttpStatus;

    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        try {
            $msg = 'AgentCheckApiSyncAccess Middleware invoked from ' . $request->ip();
            Log::info($msg, ['headers' => $request->headers->all()]);
            activityLogIt(__CLASS__, __FUNCTION__, 'info', $msg, 'agent_api_token', '', '', '');

            // Reject old param name
            if ($request->has('api_token')) {
                $msg = 'Deprecated parameter "api_token" used from ' . $request->ip();
                Log::warning($msg);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->failureResponse('Token param name needs to be updated to apitoken.', 422);
            }

            // Accept from either header or query param
            $apitoken = $request->header('apitoken') ?? $request->query('apitoken');
            if (empty($apitoken)) {
                $msg = 'No API token provided from ' . $request->ip();
                Log::warning($msg);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized();
            }

            // Validate UUID format
            if (!Str::isUuid($apitoken)) {
                $msg = 'Invalid token ID format from ' . $request->ip();
                Log::warning($msg);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized('Invalid token ID format');
            }

            // Find agent
            $agent = Agent::where('api_token', $apitoken)->first();
            if (!$agent) {
                $msg = 'Invalid API token from ' . $request->ip();
                Log::warning($msg, ['apitoken' => $apitoken]);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized('Invalid API token');
            }

            // Verify IP address
            if ($agent->srcip !== $request->ip()) {
                $msg = 'Unauthorized IP address from ' . $request->ip();
                Log::warning($msg, ['expected_ip' => $agent->srcip, 'actual_ip' => $request->ip()]);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized('Unauthorized IP address');
            }

            // Pass agent ID
            app()->instance('agent_id', $agent->id);

            $msg = 'Agent authorized with ID ' . $agent->id . ' from ' . $request->ip();
            Log::info($msg);
            activityLogIt(__CLASS__, __FUNCTION__, 'info', $msg, 'agent_api_token', '', '', '');

            return $next($request);
        } catch (\Exception $e) {
            $msg = 'Exception in AgentCheckApiSyncAccess Middleware from ' . $request->ip();
            Log::error($msg, ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->failureResponse('An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function respondUnauthorized($msg = 'Unauthorized')
    {
        $fullMsg = 'Unauthorized access attempt from ' . request()->ip() . ': ' . $msg;
        Log::info($fullMsg);
        activityLogIt(__CLASS__, __FUNCTION__, 'info', $fullMsg, 'agent_api_token', '', '', '');
        return $this->failureResponse($fullMsg, 401);
    }
}
