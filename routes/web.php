<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FactorialAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/oauth/factorial/redirect', [FactorialAuthController::class, 'redirect']);
Route::get('/oauth/factorial/callback', [FactorialAuthController::class, 'callback']);
