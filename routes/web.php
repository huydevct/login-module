<?php

use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Controllers\Web\AuthController;

Route::prefix('login')->group(function () {
    Route::get('/', [AuthController::class, 'login'])->name('login');
    Route::post('/', [AuthController::class, 'loginPost'])->name('login.post');
});

Route::middleware('auth')->group(function () {
    Route::get('/logout', [AuthController::class, 'logout'])->name('logout');
});
