<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QueryFilters\QueryFilterMultipleFields;
use App\Models\Device;
use App\Services\Notifications\AgentDeviceDownloadCompletedNotifier;
use App\Services\Notifications\AgentDeviceFailureNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentQueue;
use Rconfig\VectorServer\Services\AgentTaskRuns\RunTrackerService;
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
                AllowedFilter::exact('retry_failed'),
                AllowedFilter::callback('created_at_between', function ($query, $value) {
                    // Accept both CSV string and array payload formats.
                    if (is_array($value)) {
                        $start = $value[0] ?? null;
                        $end = $value[1] ?? $start;
                    } else {
                        $parts = explode(',', (string) $value);
                        $start = $parts[0] ?? null;
                        $end = $parts[1] ?? $start;
                    }

                    if (! $start || ! $end) {
                        return;
                    }

                    $query->whereBetween('created_at', [
                        Carbon::parse($start)->startOfDay(),
                        Carbon::parse($end)->endOfDay(),
                    ]);
                }),
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
        $agent = Agent::find(app('agent_id'));

        if (! $agent || $agent->id === 1) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jobs = AgentQueue::where('processed', 0)
            ->where('retry_failed', 0)
            ->where('agent_id', $agent->id)
            ->get();

        return response()->json(array_values($jobs->toArray()));
    }

    public function mark_as_processed($ulid)
    {
        $job = AgentQueue::where('ulid', $ulid)->first();

        if (! $job) {
            return response()->json(['error' => 'Job not found'], 422);
        }

        $alreadyProcessed = (bool) $job->processed;

        $job->processed = 1;
        $job->save();

        (new RunTrackerService)->markUnitSuccessByUlid($job->ulid);

        // Update device status since a job was successfully processed
        $this->updateDeviceStatus($job->device_id, 1);

        // A manual "download now" against an agent device is only queued in
        // DownloadConfigNowJob and reports completion here, when the agent actually
        // uploads the config, instead of at dispatch time (RCO-1253 #4).
        $this->notifyManualDownloadCompleted($job, $alreadyProcessed);

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

    public function mark_as_failed($ulid)
    {
        $job = AgentQueue::where('ulid', $ulid)->first();

        if (! $job) {
            return response()->json(['error' => 'Job not found'], 422);
        }

        if ($job->retry_attempt > 0) {
            $job->retry_attempt--;
        }

        if ($job->retry_attempt === 0) {
            $job->retry_failed = 1;
            $job->save();

            $transitioned = (new RunTrackerService)->markUnitFailedByUlid($job->ulid, 'Job processing failed by agent.');
            $this->updateDeviceStatus($job->device_id, 0);

            // Event-driven Device Connection Failure notification: fire once, at the
            // authoritative failure (retries exhausted), rather than from the server
            // timeout watchdog which false-positives on large agent fleets.
            if ($transitioned) {
                (new AgentDeviceFailureNotifier)->notifyDeviceFailure((int) $job->device_id, 'Job processing failed by agent.', $job->task_run_id);
            }
        } else {
            $job->save();
        }

        return response()->json(['success' => true]);
    }

    private function updateDeviceStatus($deviceId, $status)
    {
        Device::where('id', $deviceId)->update(['status' => $status]);
    }

    /**
     * Fire the manual config-download completion notification for an agent device.
     *
     * Only manual "download now" jobs trigger this: those carry no task_run_id, whereas
     * scheduled task runs report completion through their own task pipeline and must not
     * raise a per-device download email. A manual download can span several queue rows
     * (one per command), so this only fires once the device has no unprocessed manual
     * rows left, and never on a repeated callback for an already-processed row. Delegates
     * to the host app notifier when present so the package still runs standalone.
     */
    private function notifyManualDownloadCompleted(AgentQueue $job, bool $alreadyProcessed): void
    {
        if ($alreadyProcessed) {
            return;
        }

        // Without the task-tracking column we cannot tell manual from scheduled, so stay
        // quiet rather than risk emailing on every scheduled agent backup.
        if (! Schema::hasColumn('agent_queues', 'task_run_id') || $job->task_run_id !== null) {
            return;
        }

        // Wait until every command row for this manual download has been processed, so a
        // multi-command device produces a single completion notification. Failed rows
        // (retry_failed = 1, incl. stale rows the cleanup marks) are excluded: otherwise a
        // single abandoned manual row would block the completion of every later manual
        // download for that device indefinitely.
        $manualRowsPending = AgentQueue::where('device_id', $job->device_id)
            ->whereNull('task_run_id')
            ->where('processed', 0)
            ->where('retry_failed', 0)
            ->exists();

        if ($manualRowsPending) {
            return;
        }

        $notifierClass = AgentDeviceDownloadCompletedNotifier::class;
        if (! class_exists($notifierClass)) {
            return;
        }

        $seconds = $job->created_at ? round(abs(now()->diffInSeconds($job->created_at)), 2) : null;

        (new $notifierClass)->notifyDeviceDownloadCompleted((int) $job->device_id, $seconds);
    }

    public function get_unprocessed(Request $request)
    {
        $this->authorize('agent.view');

        // Get IDs from request body
        $ids = $request->input('ids', []);

        if (empty($ids) || ! is_array($ids)) {
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
