
<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;

Route::apiResource('agents-queues',  AgentQueueController::class)->only(['index', 'show']);
