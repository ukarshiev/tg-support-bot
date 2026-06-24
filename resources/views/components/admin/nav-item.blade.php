{{--
    Admin sidebar navigation item.

    Props:
      $href     — target URL (default "#")
      $active   — whether this item is the currently active route (default false)
      $disabled — whether this item is a placeholder (no real link; default false)
      $icon     — SVG icon markup (passed as named slot $icon, optional)
      $slot     — item label text
--}}
@props([
    'href'     => '#',
    'active'   => false,
    'disabled' => false,
])

@php
    $baseClasses = 'flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition-colors';
    $stateClasses = $active
        ? 'bg-sidebar-active text-text-sidebar'
        : ($disabled
            ? 'cursor-default text-text-sidebar-secondary opacity-50'
            : 'text-text-sidebar-secondary hover:bg-sidebar-hover hover:text-text-sidebar');
@endphp

@if ($disabled)
    <span {{ $attributes->merge(['class' => "$baseClasses $stateClasses"]) }}
          aria-disabled="true">
        @if (isset($icon))
            <span class="h-4 w-4 shrink-0">{{ $icon }}</span>
        @endif
        <span>{{ $slot }}</span>
    </span>
@else
    <a href="{{ $href }}"
       {{ $attributes->merge(['class' => "$baseClasses $stateClasses"]) }}
       @if ($active) aria-current="page" @endif>
        @if (isset($icon))
            <span class="h-4 w-4 shrink-0">{{ $icon }}</span>
        @endif
        <span>{{ $slot }}</span>
    </a>
@endif
