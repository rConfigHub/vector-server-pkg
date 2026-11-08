<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\Api\v2\AgentProvisionController;

// The token needs the agents.write ability (RCO-2024); the owner's agent permissions are checked in ProvisionAgentRequest.
Route::post('agents/provision', [AgentProvisionController::class, 'provision'])
    ->middleware('abilities:agents.write')
    ->name('api.v2.vector.agents.provision');
