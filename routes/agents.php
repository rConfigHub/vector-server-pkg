<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentController;
use Rconfig\VectorServer\Http\Controllers\VectorBinaryController;

Route::post('agents/update-roles/{id}', [AgentController::class, 'updateRoles']);
Route::post('agents/{id}/add-to-user', [AgentController::class, 'addAgentToUser']);
Route::post('agents/{id}/regenerate-api-token', [AgentController::class, 'regenerateToken']);
Route::post('agents/{id}/bootstrap-token', [AgentController::class, 'generateBootstrapToken']);
Route::post('agents/{id}/rotate-runtime-key', [AgentController::class, 'rotateRuntimeKey']);
Route::get('agents/{id}/install-command', [AgentController::class, 'installCommand']);
Route::get('vector/binaries/active', [VectorBinaryController::class, 'active']);
Route::get('vector/binaries', [VectorBinaryController::class, 'index']);
Route::post('vector/binaries/download', [VectorBinaryController::class, 'download']);
Route::post('vector/binaries/activate', [VectorBinaryController::class, 'activate']);
Route::post('vector/binaries/delete', [VectorBinaryController::class, 'delete']);
Route::get('agents/filters', [AgentController::class, 'filters']);
Route::get('agents/active',  [AgentController::class, 'getActiveAgent']);
Route::get('agents/latest', [AgentController::class, 'getLatestAgents']);
Route::middleware(['agent.attach.id'])->apiResource('agents',  AgentController::class);
Route::post('/agents/delete-many', [AgentController::class, 'deleteMany']);
Route::post('/agents/{id}/enable', [AgentController::class, 'enable']);
Route::post('/agents/{id}/disable', [AgentController::class, 'disable']);
