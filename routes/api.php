<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IclockController;

Route::prefix('iclock')->group(function () {
    Route::match(['GET', 'POST'], '/ping', [IclockController::class, 'ping']);
    Route::match(['GET', 'POST'], '/getrequest', [IclockController::class, 'getRequest']);
    Route::match(['GET', 'POST'], '/cdata', [IclockController::class, 'cdata']);
    Route::match(['GET', 'POST'], '/registry', [IclockController::class, 'registry']);
    Route::match(['GET', 'POST'], '/push', [IclockController::class, 'push']);
    Route::match(['GET', 'POST'], '/devicecmd', [IclockController::class, 'devicecmd']);
});
