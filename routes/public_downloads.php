<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\VectorBinaryDownloadController;

Route::get('/vector/downloads/vectoragent-latest', [VectorBinaryDownloadController::class, 'latest']);
Route::get('/vector/downloads/vectoragent-latest.sha256', [VectorBinaryDownloadController::class, 'latestSha']);
