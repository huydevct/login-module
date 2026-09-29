<?php

use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Controllers\Api\V1\AuthController;

/*
| Prefix lay tu config login.api.prefix (mac dinh api/v1/auth).
*/

Route::post('/add-device', [AuthController::class, 'addDevice'])->name('login.api.add-device');
