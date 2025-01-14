<?php

namespace  Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller as Controller;

class ApiTestController extends Controller
{
    public function index()
    {
        $response = [
            'success' => true,
            'message' => 'Welcome to rConfig Agent Sync API. If you are not an authorized agent, you do not have permissions to use this API.',
        ];

        return response()->json($response, 200);
    }
}
