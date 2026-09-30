<?php

namespace Modules\Login\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Login\Console\CreateDeviceToken;
use Modules\Login\Console\CreateUserCms;
use Modules\Login\Http\Middleware\ApiAuthenticate;
use Modules\Login\Http\Middleware\VerifyDeviceSignature;

class LoginServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Login';

    protected string $moduleNameLower = 'login';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerTranslations();
        $this->registerViews();
        $this->registerAssets();
        $this->registerMiddleware();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerCommands(): void
    {
        $this->commands([
            CreateUserCms::class,
            CreateDeviceToken::class,
        ]);
    }

    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'lang'));
        }
    }

    protected function registerConfig(): void
    {
        $this->publishes([module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower.'.php')], 'login-config');
        $this->mergeConfigFrom(module_path($this->moduleName, 'config/config.php'), $this->moduleNameLower);
    }

    /**
     * Views dung chung namespace `login::`, component anonymous trong
     * resources/views/components goi bang <x-login::sidebar />.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', 'login-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);

        View::composer($this->moduleNameLower.'::*', function ($view) {
            $view->with('loginAssets', rtrim((string) config('login.cms.assets_url'), '/'));
        });
    }

    /**
     * Assets CoreUI (css/js/icon) duoc publish ra public/modules/login.
     */
    protected function registerAssets(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'public') => public_path('modules/'.$this->moduleNameLower),
        ], ['login-assets', 'laravel-assets']);
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $alias = config('login.api.middleware_alias');
        if (! empty($alias)) {
            $router->aliasMiddleware($alias, ApiAuthenticate::class);
        }

        $signedAlias = config('login.attestation.middleware_alias');
        if (config('login.attestation.enabled') && ! empty($signedAlias)) {
            $router->aliasMiddleware($signedAlias, VerifyDeviceSignature::class);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->moduleNameLower)) {
                $paths[] = $path.'/modules/'.$this->moduleNameLower;
            }
        }

        return $paths;
    }
}
