{{--
    Secondary action button — white background, light border, dark text.
--}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center rounded-lg border border-border-light bg-bg-primary px-5 py-2.5 text-sm font-semibold text-text-primary transition hover:bg-bg-secondary focus:outline-none focus:ring-2 focus:ring-border-light focus:ring-offset-2 disabled:opacity-60']) }}>
    {{ $slot }}
</button>
