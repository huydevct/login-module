<!DOCTYPE html><!--
* CoreUI - Free Bootstrap Admin Template
* @version v4.2.2
* @link https://coreui.io/product/free-bootstrap-admin-template/
* Copyright (c) 2023 creativeLabs Łukasz Holeczek
* Licensed under MIT (https://github.com/coreui/coreui-free-bootstrap-admin-template/blob/main/LICENSE)
-->
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <base href="/">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('login.cms.title'))</title>
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $loginAssets }}/assets/favicon/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ $loginAssets }}/assets/favicon/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $loginAssets }}/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="{{ $loginAssets }}/assets/favicon/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $loginAssets }}/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="{{ $loginAssets }}/assets/favicon/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="{{ $loginAssets }}/assets/favicon/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
    <!-- Vendors styles-->
    <link rel="stylesheet" href="{{ $loginAssets }}/vendors/simplebar/css/simplebar.css">
    <!-- Main styles for this application-->
    <link href="{{ $loginAssets }}/css/style.css" rel="stylesheet">
    <link href="{{ $loginAssets }}/css/examples.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/gh/lelinh014756/fui-toast-js@master/assets/css/toast@1.0.1/fuiToast.min.css">
    @stack('style')
</head>
<body>
{{-- Trang khong can sidebar/header (vd trang login) khai bao @section('guest', true). --}}
@hasSection('guest')
    @yield('content')
@else
    <x-login::sidebar />
    <div class="wrapper d-flex flex-column min-vh-100 bg-light">
        <x-login::header />
        @yield('content')
        <x-login::footer />
    </div>
@endif
<div id="fui-toast"></div>
<script src="{{ $loginAssets }}/vendors/@coreui/coreui/js/coreui.bundle.min.js"></script>
<script src="{{ $loginAssets }}/vendors/simplebar/js/simplebar.min.js"></script>
<script src="{{ $loginAssets }}/sweetalert2.all.min.js"></script>
<script src="https://code.jquery.com/jquery-3.1.1.min.js" integrity="sha256-hVVnYaiADRTO2PzUGmuLJr8BLUSjGIZsDYGmIJLv2b8=" crossorigin="anonymous"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/gh/lelinh014756/fui-toast-js@master/assets/js/toast@1.0.1/fuiToast.min.js"></script>
<script>
    window.$Toast = {
        success: window.FuiToast.success,
        error: window.FuiToast.error,
        warning: window.FuiToast.warning
    };
    window.$app_domain = @json(config('app.url', ''));
    @auth
    window.$user_auth = @json(['id' => (string) auth()->id(), 'name' => auth()->user()->name, 'login_name' => auth()->user()->login_name]);
    @endauth
</script>
@stack('scripts')
</body>
</html>
