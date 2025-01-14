<?php

// routes/api.php
use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\AgentConfigUploadController;
use Rconfig\VectorServer\Http\Controllers\AgentLogController;
use Rconfig\VectorServer\Http\Controllers\AgentQueueController;
use Rconfig\VectorServer\Http\Controllers\AgentSyncController;
use Rconfig\VectorServer\Http\Controllers\ApiTestController;


Route::prefix('api/agentsync')->group(function () {
    Route::get('apitest', [ApiTestController::class, 'index']);

    Route::get('/agent-queue/unprocessed', [AgentQueueController::class, 'get_unprocessed_jobs'])->name('agent-queue.unprocessed');
    Route::post('/agent-queue/{ulid}/process', [AgentQueueController::class, 'mark_as_processed'])->name('agent-queue.process');
    Route::post('/agent-queue/upload-config', [AgentConfigUploadController::class, 'upload_config'])->name('agent-results.uploadConfig');

    Route::post('/logs/ingest', [AgentLogController::class, 'log_ingest'])->name('log.ingest');

    Route::get('/status', [AgentSyncController::class, 'status']);
    Route::get('/sync', [AgentSyncController::class, 'sync']);
});
