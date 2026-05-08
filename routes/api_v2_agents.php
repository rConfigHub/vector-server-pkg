<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\Api\v2\AgentProvisionController;

Route::post('agents/provision', [AgentProvisionController::class, 'provision'])
    ->name('api.v2.vector.agents.provision');
