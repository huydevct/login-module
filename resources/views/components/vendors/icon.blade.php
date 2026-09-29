@props(['name' => '', 'class' => ''])
<svg class="nav-icon {{ $class }}">
    <use xlink:href="{{ $loginAssets }}/vendors/@coreui/icons/svg/free.svg#{{ $name }}"></use>
</svg>
