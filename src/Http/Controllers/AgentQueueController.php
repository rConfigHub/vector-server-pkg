<?php

namespace  Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QueryFilters\QueryFilterMultipleFields;
use App\Models\Device;
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
                AllowedFilter::custom('q', new QueryFilterMultipleFields, 'device_id, ip_address'),
                AllowedFilter::exact('device_id'),
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('processed'),
                AllowedFilter::callback('newer_than', function ($query, $value) {
                    $query->where('id', '>', $value);
                }),
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

        /* 
        * SUMMARY OF RETRY LOGIC:
        * 
        * When this method is called:
        * 1. Jobs with retry_attempt = 0 → Marked as permanently failed (retry_failed = 1)
        * 2. Jobs with retry_attempt > 0 → Retry count decremented, job remains available
        * 3. Only jobs that aren't marked as failed are returned to the caller
        * 4. Device status is updated based on job outcomes for affected devices
        * 
        * IMPORTANT: Jobs marked as retry_failed = 1 will no longer appear in future
        * calls to this method due to the initial WHERE clause filtering them out.
        */

        $agent = Agent::find(app('agent_id'));

        if (!$agent || $agent->id === 1) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jobs = AgentQueue::where('processed', 0)
            ->where('retry_failed', 0)
            ->where('agent_id', $agent->id)
            ->get();

        // Keep track of devices that need status updates
        $devicesNeedingUpdate = collect();

        foreach ($jobs as $job) {
            // $job->connection_params = json_decode($job->connection_params, true); // hard coding the cast here because it's not working in the model
            if ($job->retry_attempt === 0) {
                $job->retry_failed = 1;
                $job->save();

                // Track this device for status update since a job failed
                $this->updateDeviceStatus($job->device_id, 0);

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

        return response()->json(array_values($updatedJobs->toArray())); // Issue #9 fixed issue where get_unprocessed_jobs returns object sometimes
    }

    public function mark_as_processed($ulid)
    {
        $job = AgentQueue::where('ulid', $ulid)->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 422);
        }

        $job->processed = 1;
        $job->save();

        // Update device status since a job was successfully processed
        $this->updateDeviceStatus($job->device_id, 1);

        // obfuscate the connection params
        // Check if connection_params is already an array (auto-cast) or a string
        if (is_string($job->connection_params)) {
            $connectionParams = json_decode($job->connection_params, true);
        } else {
            // It's already an array due to model casting
            $connectionParams = $job->connection_params;
        }

        // Ensure we have an array to work with
        if (is_array($connectionParams)) {
            $connectionParams['password'] = '********';
            $connectionParams['enable_password'] = '********';
            // $connectionParams['private_key'] = '********';
            // $connectionParams['private_key_passphrase'] = '********';

            $job->connection_params = json_encode($connectionParams);
            $job->save();
        }

        return response()->json(['success' => true]);
    }

    private function updateDeviceStatus($deviceId, $status)
    {
        Device::where('id', $deviceId)->update(['status' => $status]);
    }

    public function get_unprocessed(Request $request)
    {
        $this->authorize('agent.view');

        // Get IDs from request body
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['data' => []]);
        }

        // Clean and validate IDs
        $ids = array_filter(array_map('intval', $ids));

        if (empty($ids)) {
            return response()->json(['data' => []]);
        }

        $query = QueryBuilder::for(AgentQueue::class)
            ->whereIn('id', $ids)
            ->allowedFilters([
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('device_id'),
            ])
            ->defaultSort('-id')
            ->get(); // Use get() instead of paginate() since we're checking specific items

        return response()->json(['data' => $query]);
    }

    public function deleteMany(Request $request)
    {
        $this->authorize('agent.delete');

        $ids = $request->ids;

        if (in_array(1, $ids)) {
            return response()->json(['error' => 'Cannot delete the first agent queue job'], 422);
        }

        AgentQueue::whereIn('id', $ids)->delete();

        return response()->json(['success' => 'Agent Queue Jobs deleted successfully']);
    }

    public function purgeQueues($agentid = null)
    {
        $this->authorize('agent.delete');

        if ($agentid) {
            $queue = AgentQueue::where('agent_id', $agentid)->delete();
        } else {
            $queue = AgentQueue::truncate();
        }

        return response()->json(['success' => 'Agent Queues purged successfully']);
    }
}
