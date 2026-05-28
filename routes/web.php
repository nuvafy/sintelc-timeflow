<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FactorialAuthController;
use App\Http\Controllers\IclockController;
use App\Models\Client;

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

Route::view('clients', 'clients')
    ->middleware(['auth'])
    ->name('clients');

Route::get('clients/{client}', fn(Client $client) => view('clients.show', compact('client')))
    ->middleware(['auth', 'verified'])
    ->name('clients.show');

Route::get('clients/{client}/records', fn(Client $client) => view('clients.records', compact('client')))
    ->middleware(['auth', 'verified'])
    ->name('clients.records');


Route::view('employees', 'employees')
    ->middleware(['auth'])
    ->name('employees');

Route::get('templates/empleados.csv', function () {
    $bom  = "\xEF\xBB\xBF";
    $rows = "pin,nombre\r\n1001,Tony Stark\r\n1002,Steve Rogers\r\n";
    return response($bom . $rows, 200, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="plantilla-empleados.csv"',
    ]);
})->middleware(['auth'])->name('templates.empleados');

// Factorial OAuth
Route::get('/oauth/factorial/redirect', [FactorialAuthController::class, 'redirect'])->name('oauth.factorial.redirect');
Route::get('/oauth/factorial/callback', [FactorialAuthController::class, 'callback'])->name('oauth.factorial.callback');

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
