@extends('login::layouts.master')

@section('title', 'Dashboard · '.config('login.cms.title'))

@section('content')
    <div class="body flex-grow-1 px-3">
        <div class="container-lg">
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb my-0">
                    <li class="breadcrumb-item">Trang chủ</li>
                    <li class="breadcrumb-item active"><span>Dashboard</span></li>
                </ol>
            </nav>

            <div class="card mb-4">
                <div class="card-header">
                    <x-login::vendors.icon name="cil-speedometer" class="icon me-2" /><strong>Dashboard</strong>
                </div>
                <div class="card-body">
                    <h4 class="card-title mb-3">Xin chào, {{ auth()->user()?->login_name ?? auth()->user()?->name }}</h4>
                    <p class="card-text text-medium-emphasis mb-0">Bạn đã đăng nhập vào {{ config('login.cms.title') }}.</p>

                    @if (empty(config('login.cms.menu')))
                        <div class="alert alert-info mt-4 mb-0" role="alert">
                            Chưa có menu. Thêm các trang admin của project vào <code>cms.menu</code> trong
                            <code>config/login.php</code> (chạy <code>php artisan vendor:publish --tag=login-config</code> nếu chưa có file này).
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
