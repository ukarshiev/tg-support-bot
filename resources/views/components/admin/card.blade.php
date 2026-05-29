{{--
    Admin content card.

    Props:
      $title — optional card header title
--}}
@props([
    'title' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-border-light bg-bg-primary p-6']) }}>
    @if ($title)
        <h2 class="mb-5 text-sm font-semibold text-text-primary">{{ $title }}</h2>
    @endif

    {{ $slot }}
</div>
