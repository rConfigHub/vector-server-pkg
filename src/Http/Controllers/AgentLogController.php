<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Rconfig\VectorServer\Models\AgentLog;

class AgentLogController extends Controller
{

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
}
