<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FactorialAuthController;
use App\Http\Controllers\IclockController;

Route::redirect('/', '/login');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::view('devices', 'devices')
    ->middleware(['auth'])
    ->name('devices');

// Factorial OAuth
Route::get('/oauth/factorial/redirect', [FactorialAuthController::class, 'redirect']);
Route::get('/oauth/factorial/callback', [FactorialAuthController::class, 'callback']);

// Biometric devices (ZKTeco) - no auth required
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

require __DIR__.'/auth.php';
