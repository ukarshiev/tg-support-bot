{{--
    Toggle / switch input.

    Props:
      $name    — input name attribute
      $id      — input id attribute (defaults to $name)
      $checked — boolean, whether toggle is on
      $label   — visible label text (optional)
--}}
@props([
    'name'    => '',
    'id'      => null,
    'checked' => false,
    'label'   => null,
])

@php
    $inputId = $id ?? $name;
    // When bound via wire:model, Livewire controls the checked state — emitting a
    // static `checked` attribute conflicts with it and breaks two-way binding.
    $wireBound = $attributes->whereStartsWith('wire:model')->isNotEmpty();
@endphp

<label class="inline-flex cursor-pointer items-center gap-3">
    <span class="relative">
        <input
            type="checkbox"
            id="{{ $inputId }}"
            name="{{ $name }}"
            class="peer sr-only"
            @unless ($wireBound) @checked($checked) @endunless
            {{ $attributes->except(['class']) }}
        />
        <span class="block h-6 w-11 rounded-full bg-border-light transition peer-checked:bg-accent"></span>
        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
    </span>
    @if ($label)
        <span class="text-sm font-medium text-text-primary">{{ $label }}</span>
    @endif
</label>
