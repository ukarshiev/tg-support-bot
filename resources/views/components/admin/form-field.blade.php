{{--
    Admin form field wrapper: label + input slot + optional hint + error display.

    Props:
      $label  — field label text
      $for    — HTML id of the input (used for <label for="...">)
      $hint   — optional helper text shown below the input
      $error  — optional error message (shows in red below hint)
      $required — when true, appends a red asterisk to the label
--}}
@props([
    'label' => '',
    'for'   => '',
    'hint'  => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if ($label)
        <label for="{{ $for }}" class="block text-sm font-medium text-text-primary">
            {{ $label }}@if ($required)<span class="text-red-500">&nbsp;*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-text-secondary">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="text-xs text-red-500">{{ $error }}</p>
    @endif
</div>
