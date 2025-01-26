
<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;

Route::apiResource('agent-queues',  AgentQueueController::class)->only(['index', 'show']);
