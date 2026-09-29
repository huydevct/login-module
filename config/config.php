<?php

return [
    'name' => 'Login',

    /*
    |--------------------------------------------------------------------------
    | CMS (web login + CoreUI admin layout)
    |--------------------------------------------------------------------------
    |
    | Trang login /login, logout /logout va layout `login::layouts.master`
    | (kem cac component sidebar/header/footer) cho cac trang admin cua project.
    |
    */
    'web' => [
        'enabled' => true,

        // Chi user co role nay moi dang nhap duoc CMS.
        'admin_role' => 1,

        // Trang chuyen den sau khi login thanh cong: ten route hoac URL.
        'home' => env('LOGIN_MODULE_HOME', '/'),

        // Model user dung cho lenh login:create-user. null = auth.providers.users.model.
        'user_model' => null,
    ],

    'cms' => [
        'title' => env('LOGIN_MODULE_CMS_TITLE', env('APP_NAME', 'Laravel').' CMS'),
        'footer' => env('LOGIN_MODULE_CMS_FOOTER', 'Powered by CoreUI'),

        // Noi publish assets CoreUI: php artisan vendor:publish --tag=login-assets
        'assets_url' => env('LOGIN_MODULE_ASSETS_URL', '/modules/login'),

        /*
         | Menu sidebar. Moi item: label, icon (ten icon CoreUI free.svg) va route
         | (ten route) hoac url. Item co route chua dang ky se bi an.
         | Vi du: ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'cil-speedometer']
         */
        'menu' => [],

        // Link tren thanh header, cung dinh dang voi menu.
        'header_menu' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | API auth cho thiet bi (mobile app)
    |--------------------------------------------------------------------------
    |
    | POST {prefix}/add-device nhan secret (payload JSON ma hoa AES-256-CBC bang
    | openssl.device_secret) va tra ve JWT. Route cua project bao ve bang
    | middleware `auth.api` (ten doi duoc qua middleware_alias).
    |
    */
    'api' => [
        'enabled' => true,
        'prefix' => 'api/v1/auth',
        'middleware_alias' => 'auth.api',

        'secret' => env('AUTH_API_JWT_SECRET'),

        // Thoi han access token (ngay).
        'token_ttl_days' => 1.5,

        // Secret gui len add-device chi hop le trong so giay nay (bo qua khi APP_DEBUG=true).
        'secret_ttl' => 60,

        // Thoi gian cache secret cua device (giay).
        'device_secret_cache_ttl' => 259200,

        // App id giu nguyen client_id; cac app khac duoc them hau to "_{app_id}".
        'raw_client_id_app_ids' => [],

        // Redis set chua id device bi khoa. null = khong kiem tra.
        'blocked_devices_redis_key' => env('LOGIN_MODULE_BLOCKED_DEVICES_KEY'),
    ],

    'openssl' => [
        'device_secret' => env('JWT_OPENSSL_DEVICE_SECRET'),
    ],
];
