<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;

Route::delete('/agent-queues/purge-all/{agent_id?}', [AgentQueueController::class, 'purgeQueues']);
Route::apiResource('agent-queues',  AgentQueueController::class)->only(['index', 'show']);
Route::post('/agent-queues/delete-many', [AgentQueueController::class, 'deleteMany']);
