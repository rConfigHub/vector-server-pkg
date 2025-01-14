<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Config\SaveConfigsToDiskAndDbService;
use Illuminate\Http\Request;

class AgentConfigUploadController extends Controller
{

    public function upload_config(Request $request)
    {

        $request->validate([
            'device_id' => 'required|integer',
            'content' => 'required|string',
            'connection_params' => 'required|array',
            'ulid' => 'required|string'
        ]);

        try {
            $deviceRecord = Device::where('id', $request->device_id)->first();
            if (!$deviceRecord) {
                return response()->json(['error' => 'Device not found', 'device_id' => $request->device_id], 422);
            }
            $deviceRecord->start_time = now();
            $deviceRecord->end_time = now()->addSeconds(10);
            $utf8_content = isset($request->content) ? mb_convert_encoding($request->content, 'UTF-8') : '';
        } catch (\Exception $e) {
            \Log::error('Error in AgentConfigUploadController@upload_config: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred']);
        }

        $command = $request->connection_params['command'];

        $configSaveResult = (new SaveConfigsToDiskAndDbService('agent_download', $command, $utf8_content, $deviceRecord, 'agent_' . app('agent_id'), $request->ulid))->saveConfigs();
        return response()->json(['success' => true, 'configSaveResult' => $configSaveResult]);
    }
}
