<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QueryFilters\QueryFilterMultipleFields;
use App\Traits\RespondsWithHttpStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Http\Requests\StoreAgentRequest;
use Rconfig\VectorServer\Jobs\UpdateAgentDevicesStatusJob;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;
use Rconfig\VectorServer\Models\VectorAgentBootstrapToken;
use Rconfig\VectorServer\Services\BootstrapTokenService;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AgentController extends Controller
{
    use RespondsWithHttpStatus;

    public function index(Request $request)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        $relationships = ['roles', 'devicesLimited'];
        $query = QueryBuilder::for(Agent::class)
            ->allowedFilters([
                AllowedFilter::custom('q', new QueryFilterMultipleFields, 'id, name, email, srcip'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('is_admin_enabled'),
                AllowedFilter::exact('reported_version'),
                AllowedFilter::exact('reported_platform'),
            ])
            ->defaultSort('-id')
            ->allowedSorts('name', 'id', 'created_at', 'is_admin_enabled')
            ->allowedIncludes($relationships)
            ->with('roles', 'devicesLimited')
            ->filterByRole($userRole->id)
            ->paginate($request->perPage ?? 10);
        return response()->json($query);
    }

    public function store(StoreAgentRequest $request)
    {
        $this->authorize('agent.create');

        $roles = array_column($request->roles, 'id');
        unset($request['roles']);

        $model = Agent::create($request->toDTO()->toArray());
        $model->roles()->sync($roles);

        return $this->successResponse('Agent created successfully!', ['id' => $model->id]);
    }

    public function show($id, $relationship = null, $withCount = null)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        $query = Agent::query();

        $query->findOrFail($id);

        if ($relationship != null) {
            foreach ($relationship as $rel) {
                $query->with($rel);
            }
        }

        $result = $query->filterByRole($userRole->id)->with('roles')->first();

        return response()->json($result);
    }

    public function update($id, StoreAgentRequest $request)
    {

        $this->authorize('agent.update');

        $roles = $request->roles instanceof \Illuminate\Support\Collection ? $request->roles->pluck('id')->toArray() : array_column($request->roles, 'id');
        unset($request['roles']);

        $model = Agent::find($id);
        $model = tap($model)->update($request->toDTO()->toArray()); // tap returns model instead of boolean
        $model->roles()->sync($roles);

        if (! $model->roles()->find(1)) {
            $model->roles()->attach([1]);
        }

        return $this->successResponse('Agent edited successfully!', ['id' => $model->id]);
    }

    public function destroy($id, $return = 0)
    {
        $this->authorize('agent.delete');

        if ($id == 1) {
            return $this->failureResponse('You cannot delete the Vector agent!', 422);
        }

        $model = Agent::find($id);
        $model = tap($model)->delete(); // tap returns model instead of boolean
        $model->roles()->detach();

        return $this->successResponse('Agent deleted successfully!', ['id' => $model->id]);
    }

    public function deleteMany(Request $request)
    {
        $this->authorize('agent.delete');

        $ids = $request->ids;

        if (in_array(1, $ids)) {
            return $this->failureResponse('You cannot delete the Vector agent!', 422);
        }

        Agent::whereIn('id', $ids)->delete();

        return $this->successResponse('Agents deleted successfully!');
    }

    public function updateRoles(Request $request)
    {
        $this->authorize('snippet.update');

        $model = $model = Agent::find($request->id);
        $model->roles()->sync($request->roles);
        if (! $model->roles()->find(1)) {
            $model->roles()->attach([1]);
        }

        return $this->successResponse('Agent roles updated successfully!', ['id' => $model->id]);
    }

    public function regenerateToken($id)
    {
        $this->authorize('agent.update');

        $model = Agent::findOrFail($id);
        $model->api_token = Str::uuid()->toString();
        $model->save();

        return $this->successResponse('Token regenerated successfully!', ['id' => $model->api_token]);
    }

    public function generateBootstrapToken($id, BootstrapTokenService $tokenService)
    {
        $this->authorize('agent.update');

        $agent = Agent::findOrFail($id);

        VectorAgentBootstrapToken::where('agent_id', $agent->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $ttlMinutes = 60;
        $rawToken = $tokenService->generate($agent->id, $ttlMinutes);
        $tokenHash = hash('sha256', $rawToken);
        $token = VectorAgentBootstrapToken::where('token_hash', $tokenHash)->first();

        return response()->json([
            'token' => $rawToken,
            'expires_at' => $token?->expires_at ?? now()->addMinutes($ttlMinutes),
        ]);
    }

    public function rotateRuntimeKey($id)
    {
        $this->authorize('agent.update');

        $agent = Agent::findOrFail($id);
        $agent->runtime_key = Str::uuid()->toString();
        $agent->runtime_key_rotated_at = now();
        $agent->save();

        return response()->json([
            'runtime_key' => $agent->runtime_key,
            'rotated_at' => $agent->runtime_key_rotated_at,
        ]);
    }

    public function installCommand($id, Request $request, BootstrapTokenService $tokenService)
    {
        $this->authorize('agent.view');

        $agent = Agent::findOrFail($id);
        $ttlMinutes = 60;
        $rawToken = $tokenService->generate($agent->id, $ttlMinutes);
        $tokenHash = hash('sha256', $rawToken);
        $token = VectorAgentBootstrapToken::where('token_hash', $tokenHash)->first();

        $serverUrl = $request->getSchemeAndHttpHost();
        $command = 'curl -kfsSL "' . $serverUrl . '/vector/install.sh?bootstrap_token=' . $rawToken . '" | bash';

        return response()->json([
            'command' => $command,
            'expires_at' => $token?->expires_at ?? now()->addMinutes($ttlMinutes),
        ]);
    }

    public function filters()
    {
        $this->authorize('agent.view');

        $versions = Agent::query()
            ->whereNotNull('reported_version')
            ->distinct()
            ->orderBy('reported_version')
            ->pluck('reported_version')
            ->values();

        $platforms = Agent::query()
            ->whereNotNull('reported_platform')
            ->distinct()
            ->orderBy('reported_platform')
            ->pluck('reported_platform')
            ->values();

        return $this->successResponse('Agent filters loaded.', [
            'reported_versions' => $versions,
            'reported_platforms' => $platforms,
        ]);
    }

    public function enable($id)
    {
        $this->authorize('agent.update');

        $model = Agent::findOrFail($id);

        DB::transaction(function () use ($model) {
            // Enable the agent
            $model->is_admin_enabled = 1;

            // Reset the agent's state for a fresh start
            $model->status = Agent::STATUS_HEALTHY;
            $model->missed_checkins = 0; // Reset missed checkins
            $model->next_scheduled_checkin_at = now()->addSeconds($model->checkin_interval); // Schedule next check-in

            $model->save();

            // Log the enable action
            AgentLog::create([
                'agent_id' => $model->id,
                'executed_at' => now(),
                'log_level' => 'INFO',
                'message' => "Agent {$model->name} administratively ENABLED and reset",
                'operation' => 'admin_enable',
                'context_data' => json_encode([
                    'reset_missed_checkins' => true,
                    'reset_status' => 'healthy',
                    'next_checkin_scheduled' => $model->next_scheduled_checkin_at
                ]),
                'entity_type' => 'AgentController',
                'entity_id' => $model->id,
            ]);

            // If agent has devices, trigger recovery of their statuses
            if ($model->devices()->count() > 0) {
                dispatch(new UpdateAgentDevicesStatusJob(
                    $model->id,
                    1, // Healthy status
                    'Agent administratively enabled'
                ))->onQueue('rConfigDefault');
            }
        });

        return $this->successResponse('Agent enabled successfully and reset!', [
            'id' => $model->id,
            'status' => 'healthy',
            'next_checkin' => $model->next_scheduled_checkin_at
        ]);
    }

    public function disable($id)
    {
        $this->authorize('agent.update');

        $model = Agent::findOrFail($id);

        DB::transaction(function () use ($model) {
            $wasHealthy = $model->status == 1;

            // Disable the agent
            $model->is_admin_enabled = 0;

            // Set status to indicate disabled state (you might want a specific status code for this)
            $model->status = Agent::STATUS_DISABLED;

            $model->save();

            // Log the disable action
            AgentLog::create([
                'agent_id' => $model->id,
                'executed_at' => now(),
                'log_level' => 'WARN',
                'message' => "Agent {$model->name} administratively DISABLED",
                'operation' => 'admin_disable',
                'context_data' => json_encode([
                    'previous_status' => $wasHealthy ? 'healthy' : 'down',
                    'disabled_by_admin' => true
                ]),
                'entity_type' => 'AgentController',
                'entity_id' => $model->id,
            ]);

            // If agent has devices, mark them as affected by disabled agent
            if ($model->devices()->count() > 0) {
                dispatch(new UpdateAgentDevicesStatusJob(
                    $model->id,
                    301, // Disabled agent status code
                    'Agent administratively disabled'
                ))->onQueue('rConfigDefault');
            }
        });

        return $this->successResponse('Agent disabled successfully!', [
            'id' => $model->id,
            'status' => 'disabled'
        ]);
    }
}
