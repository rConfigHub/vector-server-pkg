<?php

use Illuminate\Support\Facades\Route;
use Rconfig\VectorServer\Http\Controllers\VectorInstallScriptController;

Route::get('/vector/install.sh', [VectorInstallScriptController::class, 'install'])
    ->middleware('throttle:60,1');
