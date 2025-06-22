<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;

// Specific routes FIRST (before any routes with parameters)
Route::post('/agent-queues/unprocessed', [AgentQueueController::class, 'get_unprocessed']);
Route::delete('/agent-queues/purge-all/{agent_id?}', [AgentQueueController::class, 'purgeQueues']);
Route::post('/agent-queues/delete-many', [AgentQueueController::class, 'deleteMany']);

// Generic resource routes LAST (these include {id} parameter)
Route::apiResource('agent-queues', AgentQueueController::class)->only(['index', 'show']);
