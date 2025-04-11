<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;

Route::apiResource('agent-queues',  AgentQueueController::class)->only(['index', 'show']);
Route::post('/agent-queues/delete-many', [AgentQueueController::class, 'deleteMany']);
