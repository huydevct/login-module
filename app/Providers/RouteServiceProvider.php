<?php

namespace Modules\Login\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        if (config('login.api.enabled')) {
            Route::prefix(config('login.api.prefix'))
                ->middleware('api')
                ->group(module_path('Login', '/routes/api.php'));
        }

        if (config('login.web.enabled')) {
            Route::middleware('web')
                ->group(module_path('Login', '/routes/web.php'));
        }
    }
}
