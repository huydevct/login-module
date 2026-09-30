<?php

use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Controllers\Api\V1\AttestController;
use Modules\Login\Http\Controllers\Api\V1\AuthController;
use Modules\Login\Http\Middleware\ApiAuthenticate;

/*
| Prefix lay tu config login.api.prefix (mac dinh api/v1/auth).
*/

Route::post('/add-device', [AuthController::class, 'addDevice'])->name('login.api.add-device');

if (config('login.attestation.enabled')) {
    Route::middleware(ApiAuthenticate::class)->prefix('attest')->group(function () {
        Route::post('/challenge', [AttestController::class, 'challenge'])->name('login.api.attest.challenge');
        Route::post('/register', [AttestController::class, 'register'])->name('login.api.attest.register');
    });
}
