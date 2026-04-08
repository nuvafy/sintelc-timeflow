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

Route::prefix('iclock')->group(function () {
    Route::get('/ping', [IclockController::class, 'ping']);
    Route::get('/getrequest', [IclockController::class, 'getRequest']);
    Route::any('/cdata', [IclockController::class, 'cdata']);
});
