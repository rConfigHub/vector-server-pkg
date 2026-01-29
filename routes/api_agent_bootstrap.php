<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\VectorAgentBootstrapController;

Route::post('/vector/agents/bootstrap', [VectorAgentBootstrapController::class, 'bootstrap'])
    ->middleware('throttle:60,1');
