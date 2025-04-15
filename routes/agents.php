<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentController;

Route::post('agents/update-roles/{id}', [AgentController::class, 'updateRoles']);
Route::post('agents/{id}/add-to-user', [AgentController::class, 'addAgentToUser']);
Route::post('agents/{id}/regenerate-api-token', [AgentController::class, 'regenerateToken']);
Route::get('agents/active',  [AgentController::class, 'getActiveAgent']);
Route::get('agents/latest', [AgentController::class, 'getLatestAgents']);
Route::middleware(['agent.attach.id'])->apiResource('agents',  AgentController::class);
Route::post('/agents/delete-many', [AgentController::class, 'deleteMany']);
Route::post('/agents/{id}/enable', [AgentController::class, 'enable']);
Route::post('/agents/{id}/disable', [AgentController::class, 'disable']);
