<?php

use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Autenticación stateful (cookies HttpOnly) para el frontend principal Kore.
// Requiere que frontend y backend compartan dominio raíz.
Route::middleware(['web'])->group(function () {
    Route::post('/auth/login', [WebAuthController::class, 'login']);
    Route::post('/register', [WebAuthController::class, 'register']);

    Route::get('/email/verify/{id}/{hash}', [WebAuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware(['auth'])->group(function () {
        Route::get('/auth/me', [WebAuthController::class, 'me']);
        Route::post('/auth/logout', [WebAuthController::class, 'logout']);
        Route::post('/email/resend', [WebAuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:6,1');
    });
});
