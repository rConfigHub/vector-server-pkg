<?php

namespace  Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Api\FilterMultipleFields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentQueue;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AgentQueueController extends Controller
{

    public function index(Request $request)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        $searchCols = ['name', 'email'];
        $query = QueryBuilder::for(AgentQueue::class)
            ->allowedFilters([
                // AllowedFilter::custom('q', new FilterMultipleFields, 'id, device_name, device_ip, device_model'),
                // AllowedFilter::exact('category', 'category.id'),
                // AllowedFilter::exact('vendor', 'vendor.id'),
                // AllowedFilter::exact('tag', 'tag.id'),
                // AllowedFilter::exact('agent', 'agent.id'),
                AllowedFilter::exact('device_id'),
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('processed'),
            ])
            ->defaultSort('-id')
            ->allowedSorts('id', 'agent_id', 'device_id', 'processed')
            ->paginate($request->perPage ?? 10);
        return response()->json($query);
    }

    public function show($id)
    {
        $this->authorize('agent.view');

        $agent = AgentQueue::findOrFail($id);
        return response()->json($agent);
    }

    public function get_unprocessed_jobs()
    {
        $agent = Agent::find(app('agent_id'));

        if (!$agent || $agent->id === 1) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jobs = AgentQueue::where('processed', 0)
            ->where('retry_failed', 0)
            ->where('agent_id', $agent->id)
            ->get();

        foreach ($jobs as $job) {
            // $job->connection_params = json_decode($job->connection_params, true); // hard coding the cast here because it's not working in the model
            if ($job->retry_attempt === 0) {
                $job->retry_failed = 1;
                continue;
            }
            if ($job->retry_attempt > 0) {
                $job->retry_attempt--;
                $job->save();
            }
        }

        // Re-fetch the jobs after updates to only return those still unprocessed and not marked as failed
        $updatedJobs = $jobs->filter(function ($job) {
            return !$job->retry_failed;
        });

        return response()->json($updatedJobs);
    }

    public function mark_as_processed($ulid)
    {
        $job = AgentQueue::where('ulid', $ulid)->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 422);
        }

        $job->processed = 1;
        $job->save();

        // obfuscate the connection params
        $connectionParams = $job->connection_params; // should be an array
        $connectionParams['password'] = '********';
        $connectionParams['enable_password'] = '********';
        // $connectionParams['private_key'] = '********';
        // $connectionParams['private_key_passphrase'] = '********';
        $job->connection_params = json_encode($connectionParams);
        $job->save();

        return response()->json(['success' => true]);
    }
}
