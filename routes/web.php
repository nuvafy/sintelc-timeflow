<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FactorialAuthController;
use App\Http\Controllers\IclockController;
use App\Models\FactorialConnection;
use App\Services\FactorialService;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/oauth/factorial/redirect', [FactorialAuthController::class, 'redirect']);
Route::get('/oauth/factorial/callback', [FactorialAuthController::class, 'callback']);

Route::get('/factorial/employees/test', function () {
    $connection = FactorialConnection::firstOrFail();
    $service = new FactorialService($connection);

    return response()->json($service->getEmployees());
});

Route::prefix('iclock')
    ->middleware('iclock')
    ->group(function () {
        Route::match(['GET', 'POST'], '/ping', [IclockController::class, 'ping']);
        Route::match(['GET', 'POST'], '/getrequest', [IclockController::class, 'getRequest']);
        Route::match(['GET', 'POST'], '/cdata', [IclockController::class, 'cdata']);
        Route::match(['GET', 'POST'], '/registry', [IclockController::class, 'registry']);
        Route::match(['GET', 'POST'], '/push', [IclockController::class, 'push']);
        Route::match(['GET', 'POST'], '/devicecmd', [IclockController::class, 'devicecmd']);
    });
