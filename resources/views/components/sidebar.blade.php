<div class="sidebar sidebar-dark sidebar-fixed" id="sidebar">
    <div class="sidebar-brand d-none d-md-flex">
        <svg class="sidebar-brand-full" width="118" height="46" alt="CoreUI Logo">
            <use xlink:href="{{ $loginAssets }}/assets/brand/coreui.svg#full"></use>
        </svg>
        <svg class="sidebar-brand-narrow" width="46" height="46" alt="CoreUI Logo">
            <use xlink:href="{{ $loginAssets }}/assets/brand/coreui.svg#signet"></use>
        </svg>
    </div>
    <ul class="sidebar-nav" data-coreui="navigation" data-simplebar="">
        @foreach (config('login.cms.menu', []) as $item)
            <x-login::menu-link :item="$item" />
        @endforeach
        @if (\Illuminate\Support\Facades\Route::has('login.api-docs'))
            <li class="nav-item">
                <a class="nav-link" href="{{ route('login.api-docs') }}">
                    <x-login::vendors.icon name="cil-description" />
                    API Docs
                </a>
            </li>
        @endif
        <li class="nav-item">
            <a class="nav-link" href="{{ route('logout') }}">
                <x-login::vendors.icon name="cil-account-logout" />
                Đăng xuất
            </a>
        </li>
    </ul>
    <button class="sidebar-toggler" type="button" data-coreui-toggle="unfoldable"></button>
</div>
