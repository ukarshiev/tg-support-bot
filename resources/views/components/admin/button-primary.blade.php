{{--
    Primary action button — accent background, white text.
--}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center rounded-[10px] bg-accent px-6 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-60']) }}>
    {{ $slot }}
</button>
