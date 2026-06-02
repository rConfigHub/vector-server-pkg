<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentLog;

class AgentPingController extends Controller
{
    public function getDevices(Request $request)
    {
        $agent = $this->getAuthorisedAgent();

        if (!$agent) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $perPage = $request->integer('per_page', 100);

        $devices = Device::where('agent_id', $agent->id)
            ->select('id', 'device_ip')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json($devices);
    }

    public function bulkUpdatePingStatus(Request $request)
    {
        $agent = $this->getAuthorisedAgent();

        if (!$agent) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'ping_jobs'             => 'required|array',
            'ping_jobs.*.id'        => 'required|integer|exists:devices,id',
            'ping_jobs.*.status'    => 'required|integer|in:' . Device::STATUS_UNREACHABLE . ',' . Device::STATUS_REACHABLE,
            'ping_jobs.*.last_seen' => 'nullable|date',
        ]);

        // verify all device IDs belong to this agent before touching anything
        $requestedIds = collect($request->ping_jobs)->pluck('id');
        $ownedIds = Device::where('agent_id', $agent->id)
            ->whereIn('id', $requestedIds)
            ->pluck('id');

        $unauthorisedIds = $requestedIds->diff($ownedIds)->values()->toArray();

        // build the upsert payload — only for devices this agent owns
        $upsertData = collect($request->ping_jobs)
            ->filter(fn($job) => $ownedIds->contains($job['id']))
            ->map(function ($job) {
                $row = [
                    'id'     => $job['id'],
                    'status' => $job['status'],
                ];

                if (isset($job['last_seen'])) {
                    $row['last_seen'] = $job['last_seen'];
                }

                return $row;
            })
            ->values()
            ->toArray();

        $upDevices = collect($upsertData)->filter(fn($r) => isset($r['last_seen']))->values()->toArray();
        $downDevices = collect($upsertData)->filter(fn($r) => !isset($r['last_seen']))->values()->toArray();

        if (!empty($upDevices)) {
            Device::upsert($upDevices, ['id'], ['status', 'last_seen', 'updated_at']);
        }

        if (!empty($downDevices)) {
            Device::upsert($downDevices, ['id'], ['status', 'updated_at']); // last_seen NOT in update columns
        }

        $updatedCount = count($upsertData);

        $this->logBulkPingUpdate($agent, $updatedCount, $unauthorisedIds);

        return response()->json([
            'success'       => true,
            'updated_count' => $updatedCount,
            'failed_ids'    => $unauthorisedIds,
        ]);
    }


    /**
     * Log the bulk ping status update to the agent logs.
     */
    private function logBulkPingUpdate(Agent $agent, int $updatedCount, array $failedIds): void
    {
        $msg = "Agent {$agent->name} bulk ping status update: {$updatedCount} device(s) updated";

        if (!empty($failedIds)) {
            $msg .= ', ' . count($failedIds) . ' failed';
        }

        AgentLog::insert([
            'agent_id'       => $agent->id,
            'executed_at'    => now(),
            'log_level'      => empty($failedIds) ? 'DEBUG' : 'WARN',
            'message'        => $msg,
            'operation'      => 'bulk_ping_status_update',
            'context_data'   => json_encode([
                'updated_count' => $updatedCount,
                'failed_ids'    => $failedIds,
            ]),
            'entity_type'    => 'AgentPingController',
            'entity_id'      => null,
            'correlation_id' => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
    private function getAuthorisedAgent(): Agent|null
    {
        $agent = Agent::find(app('agent_id'));

        if (!$agent || $agent->id === 1) {
            return null;
        }

        return $agent;
    }
}