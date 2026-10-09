<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\VectorHubController;

Route::prefix('api/vector-hub')->group(function () {
    Route::post('/authenticate-agent', [VectorHubController::class, 'authenticateAgent'])
        ->name('vectorhub.authenticate-agent');
});
