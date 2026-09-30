@props(['item', 'linkClass' => 'nav-link', 'withIcon' => true])
@php
    $href = null;
    if (! empty($item['route'])) {
        // Bao loi thay vi an item: sai ten route trong config phai lo ra ngay khi dev.
        if (! \Illuminate\Support\Facades\Route::has($item['route'])) {
            throw new \Symfony\Component\Routing\Exception\RouteNotFoundException(sprintf(
                "Menu '%s': route [%s] chưa được đăng ký. Tạo route này hoặc sửa login.cms.menu / login.cms.header_menu trong config/login.php (kiểm tra bằng php artisan route:list).",
                $item['label'] ?? '',
                $item['route']
            ));
        }
        $href = route($item['route']);
    } elseif (! empty($item['url'])) {
        $href = url($item['url']);
    }
@endphp
@if ($href)
    <li class="nav-item">
        <a class="{{ $linkClass }}" href="{{ $href }}" @if (! empty($item['target'])) target="{{ $item['target'] }}" rel="noopener" @endif>
            @if ($withIcon && ! empty($item['icon']))
                <x-login::vendors.icon :name="$item['icon']" />
            @endif
            {{ $item['label'] ?? '' }}
        </a>
    </li>
@endif
