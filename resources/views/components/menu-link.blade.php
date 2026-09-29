@props(['item', 'linkClass' => 'nav-link', 'withIcon' => true])
@php
    $href = null;
    if (! empty($item['route'])) {
        $href = \Illuminate\Support\Facades\Route::has($item['route']) ? route($item['route']) : null;
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
