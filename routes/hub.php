<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\VectorHubManagementController;

// SPA-facing Vector Hub management (auth:sanctum group). RCO-744 Phase 2.
Route::get('vector-hub/status', [VectorHubManagementController::class, 'status']);
Route::get('vector-hub/agents', [VectorHubManagementController::class, 'agents']);
Route::post('vector-hub/agents/{id}/live-channel', [VectorHubManagementController::class, 'setAgentLiveChannel']);
Route::post('vector-hub/install', [VectorHubManagementController::class, 'install']);
Route::post('vector-hub/uninstall', [VectorHubManagementController::class, 'uninstall']);
Route::post('vector-hub/service/restart', [VectorHubManagementController::class, 'restartService']);
Route::get('vector-hub/install/{id}', [VectorHubManagementController::class, 'installStatus']);
