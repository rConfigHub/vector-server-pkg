<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\RespondsWithHttpStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Services\BootstrapTokenService;

class VectorAgentBootstrapController extends Controller
{
    use RespondsWithHttpStatus;

    public function bootstrap(Request $request, BootstrapTokenService $tokenService)
    {
        $request->validate([
            'agent_id' => 'required|integer',
            'bootstrap_token' => 'required|string',
        ]);

        $token = $tokenService->validate($request->bootstrap_token);
        if (! $token) {
            return $this->failureResponse('Invalid or expired bootstrap token.', 403);
        }

        if ((int) $token->agent_id !== (int) $request->agent_id) {
            return $this->failureResponse('Bootstrap token does not match agent.', 403);
        }

        $agent = $token->agent ?? Agent::find($request->agent_id);
        if (! $agent) {
            return $this->failureResponse('Agent not found.', 404);
        }

        if (! $agent->api_token) {
            $agent->api_token = Str::uuid()->toString();
            $agent->save();
        }

        $tokenService->burn($token);

        return $this->successResponse('Bootstrap completed.', [
            'api_token' => $agent->api_token,
        ]);
    }
}
