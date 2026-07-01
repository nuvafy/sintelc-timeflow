<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FactorialAuthController;
use App\Http\Controllers\IclockController;
use App\Models\Client;

Route::redirect('/', '/login');

// ── Rutas solo admin ─────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::view('dashboard',  'dashboard')->name('dashboard');
    Route::view('clients',    'clients')->name('clients');
    Route::view('employees',  'employees')->name('employees');
    Route::view('devices',    'devices')->name('devices');
    Route::get('clients/{client}', fn(Client $client) => view('clients.show', compact('client')))->name('clients.show');
    Route::get('clients/{client}/records', fn(Client $client) => view('clients.records', compact('client')))->name('clients.records');
});

// ── Rutas compartidas (auth) ──────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    // Cliente: aterrizaje post-login → sus registros de asistencia
    Route::get('mis-registros', function () {
        $user = auth()->user();
        if ($user->isAdmin()) return redirect()->route('dashboard');
        abort_if(!$user->client_id, 403);
        $client = Client::findOrFail($user->client_id);
        return view('clients.records', compact('client'));
    })->name('client.records');

    // Cliente: sus dispositivos
    Route::get('mis-dispositivos', function () {
        $user = auth()->user();
        if ($user->isAdmin()) return redirect()->route('devices');
        abort_if(!$user->client_id, 403);
        return view('devices');
    })->name('client.devices');
});

Route::get('templates/empleados.csv', function () {
    $bom  = "\xEF\xBB\xBF";
    $rows = "pin,nombre\r\n1001,Tony Stark\r\n1002,Steve Rogers\r\n";
    return response($bom . $rows, 200, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="plantilla-empleados.csv"',
    ]);
})->middleware(['auth'])->name('templates.empleados');

// ── Factorial OAuth ───────────────────────────────────────────────────────────
Route::get('/oauth/factorial/redirect', [FactorialAuthController::class, 'redirect'])->name('oauth.factorial.redirect');
Route::get('/oauth/factorial/callback',  [FactorialAuthController::class, 'callback'])->name('oauth.factorial.callback');

// ── Biometric devices (ZKTeco) — sin auth ────────────────────────────────────
Route::prefix('iclock')->middleware('iclock')->group(function () {
    Route::match(['GET', 'POST'], '/ping',       [IclockController::class, 'ping']);
    Route::match(['GET', 'POST'], '/getrequest', [IclockController::class, 'getRequest']);
    Route::match(['GET', 'POST'], '/cdata',      [IclockController::class, 'cdata']);
    Route::match(['GET', 'POST'], '/registry',   [IclockController::class, 'registry']);
    Route::match(['GET', 'POST'], '/push',       [IclockController::class, 'push']);
    Route::match(['GET', 'POST'], '/devicecmd',  [IclockController::class, 'devicecmd']);
});

require __DIR__.'/auth.php';
