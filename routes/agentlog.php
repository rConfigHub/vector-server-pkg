
<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentLogController;

Route::apiResource('agents-logs',  AgentLogController::class)->only(['index', 'show']);
