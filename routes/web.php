<?php

use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Controllers\Web\AuthController;

Route::prefix('login')->group(function () {
    Route::get('/', [AuthController::class, 'login'])->name('login');
    Route::post('/', [AuthController::class, 'loginPost'])->name('login.post');
});

Route::middleware('auth')->group(function () {
    Route::get('/logout', [AuthController::class, 'logout'])->name('logout');

    // Default trong code: config/login.php publish tu ban cu khong co 2 key nay (khoi 'web' bi thay ca khoi).
    if (config('login.web.admin_page', true)) {
        Route::get('/'.trim((string) config('login.web.admin_path', 'admin'), '/'), [AuthController::class, 'dashboard'])->name('login.admin');
    }
});
