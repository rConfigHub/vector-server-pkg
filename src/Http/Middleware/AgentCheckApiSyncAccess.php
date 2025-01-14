<?php

namespace Rconfig\VectorServer\Http\Middleware;

use App\Traits\RespondsWithHttpStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rconfig\VectorServer\Models\Agent;

class AgentCheckApiSyncAccess
{
    use RespondsWithHttpStatus;

    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json'); // Force JSON response

        try {
            // Log incoming request details for debugging
            $msg = 'AgentCheckApiSyncAccess Middleware invoked from ' . $request->ip();
            Log::info($msg, ['headers' => $request->headers->all()]);
            activityLogIt(__CLASS__, __FUNCTION__, 'info', $msg, 'agent_api_token', '', '', '');

            // Check for deprecated parameter name
            if ($request->has('api_token')) {
                $msg = 'Deprecated parameter "api_token" used from ' . $request->ip();
                Log::warning($msg);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '',);
                return $this->failureResponse('Token param name needs to be updated to apitoken.', 422);
            }

            // Extract and validate the API token
            $apitoken = $request->header('apitoken') ?? $request->input('apitoken');
            if (empty($apitoken)) {
                $msg = 'No API token provided from ' . $request->ip();
                Log::warning($msg);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized();
            }

            // Check if the token exists in the database
            $agent = Agent::where('api_token', $apitoken)->first();
            if (!$agent) {
                $msg = 'Invalid API token from ' . $request->ip();
                Log::warning($msg, ['apitoken' => $apitoken]);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized('Invalid API token');
            }

            // Validate source IP
            if ($agent->srcip !== $request->ip()) {
                $msg = 'Unauthorized IP address from ' . $request->ip();
                Log::warning($msg, ['expected_ip' => $agent->srcip, 'actual_ip' => $request->ip()]);
                activityLogIt(__CLASS__, __FUNCTION__, 'warning', $msg, 'agent_api_token', '', '', '');
                return $this->respondUnauthorized('Unauthorized IP address');
            }

            // Pass the agent ID to the application instance
            app()->instance('agent_id', $agent->id);
            $msg = 'Agent authorized with ID ' . $agent->id . ' from ' . $request->ip();
            Log::info($msg);
            activityLogIt(__CLASS__, __FUNCTION__, 'info', $msg, 'agent_api_token', '', '', '');

            return $next($request);
        } catch (\Exception $e) {
            // Log the exception details
            $msg = 'Exception in AgentCheckApiSyncAccess Middleware from ' . $request->ip();
            Log::error($msg, ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'headers' => $request->headers->all()]);
            activityLogIt(__CLASS__, __FUNCTION__, 'error', $msg, 'agent_api_token', '', '', '');

            return $this->failureResponse('An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function respondUnauthorized($msg = 'Unauthorized')
    {
        $msg = 'Unauthorized access attempt from ' . request()->ip() . ': ' . $msg;
        Log::info($msg);
        activityLogIt(__CLASS__, __FUNCTION__, 'info', $msg, 'agent_api_token', '', '', '');
        return $this->failureResponse($msg, 401);
    }
}
