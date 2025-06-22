<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Facades\VectorServer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\QueryFilters\QueryFilterMultipleFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rconfig\VectorServer\Models\AgentLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AgentLogController extends Controller
{

    public function index(Request $request)
    {
        $this->authorize('agent.view');

        $userRole = auth()->user()->roles()->first();

        // add agent if VectorServer is enabled
        if (VectorServer::isInstalled()) {
            $relationships[] = 'agent';
        }

        $searchCols = ['name', 'email'];
        $query = QueryBuilder::for(AgentLog::class)
            ->allowedFilters([
                AllowedFilter::custom('q', new QueryFilterMultipleFields, 'message'),
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('log_level'),
                AllowedFilter::callback('newer_than', function ($query, $value) {
                    $query->where('id', '>', $value);
                }),
            ])
            ->with($relationships)
            ->defaultSort('-id')
            ->allowedSorts('id', 'agent_id', 'log_level', 'created_at', 'operation', 'entity_type')
            ->paginate($request->perPage ?? 10);

        return response()->json($query);
    }

    public function show($id)
    {
        $this->authorize('agent.view');

        $agent = AgentLog::findOrFail($id);
        return response()->json($agent);
    }

    public function log_ingest(Request $request)
    {
        $rawlogs = json_decode($request->getContent(), true);
        try {
            $logs = [];
            foreach ($rawlogs as $key => $value) {
                $logs[] = [
                    'agent_id' => app('agent_id'),
                    'executed_at' => $value['executed_at'],
                    'log_level' => $value['log_level'],
                    'message' => $value['message'],
                    'operation' => $value['operation'],
                    'context_data' => isset($value['context_data']) ? json_encode($value['context_data']) : null,
                    'entity_type' => $value['entity_type'] ?? null,
                    'entity_id' => $value['entity_id'] ?? null,
                    'correlation_id' => $value['correlation_id'] ?? null,
                ];
            }

            // Bulk insert logs
            AgentLog::insert($logs);

            // Return success response
            return response()->json(['success' => true], 201);
        } catch (\Exception $e) {
            Log::error('Error ingesting log: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'An error occurred while processing the log'], 500);
        }
    }

    public function deleteMany(Request $request)
    {
        $this->authorize('agent.delete');

        $ids = $request->ids;
        if (empty($ids)) {
            return response()->json(['error' => 'No IDs provided'], 400);
        }

        AgentLog::destroy($ids);

        return response()->json(['success' => 'Agent Logs deleted successfully']);
    }

    public function purgeLogs($agentid = null)
    {
        $this->authorize('agent.delete');

        if ($agentid) {
            $logs = AgentLog::where('agent_id', $agentid)->delete();
        } else {
            $logs = AgentLog::truncate();
        }

        return response()->json(['success' => 'Agent Logs purged successfully']);
    }
}
