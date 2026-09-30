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

        // Trang chuyen den sau khi login thanh cong: ten route (vd login.admin) hoac duong dan (vd /admin).
        'home' => env('LOGIN_MODULE_HOME', 'login.admin'),

        // Trang admin mac dinh cua module (route login.admin, layout CoreUI + menu cms.menu).
        // Tat khi project tu lam trang admin rieng; doi admin_path neu /admin bi trung.
        'admin_page' => true,
        'admin_path' => 'admin',

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

    /*
    |--------------------------------------------------------------------------
    | Android Keystore attestation
    |--------------------------------------------------------------------------
    |
    | POST {api.prefix}/attest/challenge va /attest/register (sau auth.api) de
    | dang ky public key cua key nam trong Keystore (TEE/StrongBox). Route can bao
    | ve dung middleware ['auth.api', 'signed.device'].
    |
    */
    'attestation' => [
        'enabled' => true,
        'middleware_alias' => 'signed.device',

        /*
         | package => danh sach SHA-256 cua cert ky app ma nguoi dung thuc su cai
         | (Play App Signing: "App signing key certificate" trong Play Console).
         | Chap nhan dang "AB:CD:..." hoac hex thuong. Package khong co o day -> tu choi.
         | Vi du: 'com.cdt.game' => ['ab12...ef'],
         */
        'signature_digests' => [],

        // Tu choi thiet bi mo khoa bootloader hoac verifiedBootState khac Verified (rootOfTrust).
        // Tat khi can test tren may dev / emulator.
        'require_verified_boot' => env('LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT', true),

        // Bundle PEM root cua Google. null = file di kem module (resources/attestation/google_roots.pem).
        'roots_path' => env('LOGIN_MODULE_ATTESTATION_ROOTS'),

        'status_url' => 'https://android.googleapis.com/attestation/status',
        'status_cache_ttl' => 86400,

        // Thoi gian song cua challenge va cua so timestamp cua request ky (giay).
        'challenge_ttl' => 300,
        'timestamp_window' => 300,
    ],

    'openssl' => [
        'device_secret' => env('JWT_OPENSSL_DEVICE_SECRET'),
    ],
];
