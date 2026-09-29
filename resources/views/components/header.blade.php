<header class="header header-sticky mb-4">
    <div class="container-fluid">
        <button class="header-toggler px-md-0 me-md-3" type="button" onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()">
            <svg class="icon icon-lg">
                <use xlink:href="{{ $loginAssets }}/vendors/@coreui/icons/svg/free.svg#cil-menu"></use>
            </svg>
        </button>
        <a class="header-brand d-md-none" href="#">
            <svg width="118" height="46" alt="CoreUI Logo">
                <use xlink:href="{{ $loginAssets }}/assets/brand/coreui.svg#full"></use>
            </svg>
        </a>
        <ul class="header-nav d-none d-md-flex">
            @foreach (config('login.cms.header_menu', []) as $item)
                <x-login::menu-link :item="$item" :with-icon="false" />
            @endforeach
        </ul>
        <ul class="header-nav ms-auto ms-3">
            <li class="nav-item dropdown">
                <a class="nav-link py-0" data-coreui-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                    <div class="avatar avatar-md"><img class="avatar-img" src="{{ $loginAssets }}/assets/img/avatars/8.jpg" alt="{{ auth()->user()?->login_name }}"></div>
                </a>
                <div class="dropdown-menu dropdown-menu-end pt-0">
                    <div class="dropdown-header bg-light py-2">
                        <div class="fw-semibold">{{ auth()->user()?->name ?? 'Account' }}</div>
                    </div>
                    <a class="dropdown-item" href="{{ route('logout') }}">
                        <svg class="icon me-2">
                            <use xlink:href="{{ $loginAssets }}/vendors/@coreui/icons/svg/free.svg#cil-account-logout"></use>
                        </svg> Logout
                    </a>
                </div>
            </li>
        </ul>
    </div>
</header>
