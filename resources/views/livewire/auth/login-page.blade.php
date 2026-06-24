{{-- Admin login screen — matches the Pencil "Авторизация" design.
     Self-contained styles (this screen does not rely on the full admin
     design-system layout), so colours/sizes are inlined here.

     NOTE: Livewire 3 requires a SINGLE root element. The style tag and both
     panels must live inside the one root div below — do not add siblings. --}}

<div class="tglogin">
<style>
    .tglogin{position:fixed;inset:0;display:flex;background:#FFFFFF;font-family:'Inter',ui-sans-serif,system-ui,sans-serif;z-index:50;}
    .tglogin__brand{flex:0 0 560px;background:#1B1F2E;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:60px 48px;}
    .tglogin__bot{width:80px;height:80px;border-radius:20px;background:#4F6EF7;display:flex;align-items:center;justify-content:center;}
    .tglogin__brand-title{margin:0;color:#FFFFFF;font-size:28px;font-weight:700;line-height:1.2;}
    .tglogin__brand-desc{margin:0;max-width:360px;color:#8B92A5;font-size:15px;line-height:1.5;text-align:center;}
    .tglogin__features{display:flex;flex-direction:column;gap:16px;width:320px;margin-top:8px;}
    .tglogin__feature{display:flex;align-items:center;gap:12px;}
    .tglogin__feature span{color:#8B92A5;font-size:13px;}
    .tglogin__feature svg{color:#4F6EF7;flex:0 0 auto;}
    .tglogin__form-panel{flex:1;display:flex;align-items:center;justify-content:center;padding:60px 80px;background:#FFFFFF;}
    .tglogin__form{display:flex;flex-direction:column;gap:32px;width:400px;max-width:100%;}
    .tglogin__header{display:flex;flex-direction:column;gap:8px;}
    .tglogin__title{margin:0;color:#1A1D26;font-size:24px;font-weight:700;}
    .tglogin__subtitle{margin:0;color:#6B7280;font-size:14px;line-height:1.45;}
    .tglogin__fields{display:flex;flex-direction:column;gap:20px;}
    .tglogin__field{display:flex;flex-direction:column;gap:8px;}
    .tglogin__label{color:#1A1D26;font-size:13px;font-weight:500;}
    .tglogin__input{width:100%;box-sizing:border-box;height:44px;padding:0 14px;border:1px solid #E5E7EB;border-radius:10px;background:#FFFFFF;color:#1A1D26;font-size:14px;outline:none;transition:border-color .15s,box-shadow .15s;}
    .tglogin__input::placeholder{color:#9CA3AF;}
    .tglogin__input:focus{border-color:#4F6EF7;box-shadow:0 0 0 3px rgba(79,110,247,.18);}
    .tglogin__input--error{border-color:#EF4444;}
    .tglogin__error{margin:0;color:#EF4444;font-size:12px;}
    .tglogin__btn{height:48px;border:none;border-radius:10px;background:#4F6EF7;color:#FFFFFF;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s,opacity .15s;}
    .tglogin__btn:hover{background:#4360e6;}
    .tglogin__btn:disabled{opacity:.7;cursor:default;}
    @media (max-width:1023px){
        .tglogin__brand{display:none;}
        .tglogin__form-panel{padding:32px 24px;}
        .tglogin__form{width:100%;max-width:400px;}
    }
</style>

    {{-- ── Brand panel ─────────────────────────────────────────────── --}}
    <aside class="tglogin__brand">
        <div class="tglogin__bot">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none"
                 stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/>
                <path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>
            </svg>
        </div>

        <h1 class="tglogin__brand-title">TG Support Bot</h1>
        <p class="tglogin__brand-desc">Панель управления мультиплатформенным ботом поддержки</p>

        <div class="tglogin__features">
            @php
                $features = [
                    ['<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>', 'Чаты из Telegram, VK и MAX'],
                    ['<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>', 'ИИ-ассистент на базе GPT и GigaChat'],
                    ['<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 'Командная работа и роли'],
                    ['<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>', 'Автоответы на частые вопросы'],
                ];
            @endphp
            @foreach ($features as [$icon, $label])
                <div class="tglogin__feature">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
                    <span>{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </aside>

    {{-- ── Login form panel ────────────────────────────────────────── --}}
    <main class="tglogin__form-panel">
        <form class="tglogin__form" wire:submit="authenticate">
            <div class="tglogin__header">
                <h2 class="tglogin__title">Вход в систему</h2>
                <p class="tglogin__subtitle">Введите свои данные для доступа к панели управления</p>
            </div>

            <div class="tglogin__fields">
                <div class="tglogin__field">
                    <label class="tglogin__label" for="tglogin-email">Email</label>
                    <input
                        id="tglogin-email"
                        type="email"
                        autocomplete="email"
                        autofocus
                        wire:model="email"
                        placeholder="admin@example.com"
                        class="tglogin__input @error('email') tglogin__input--error @enderror"
                    />
                    @error('email')
                        <p class="tglogin__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="tglogin__field">
                    <label class="tglogin__label" for="tglogin-password">Пароль</label>
                    <input
                        id="tglogin-password"
                        type="password"
                        autocomplete="current-password"
                        wire:model="password"
                        placeholder="••••••••"
                        class="tglogin__input @error('password') tglogin__input--error @enderror"
                    />
                    @error('password')
                        <p class="tglogin__error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <button type="submit" class="tglogin__btn" wire:loading.attr="disabled" wire:target="authenticate">
                <span wire:loading.remove wire:target="authenticate">Войти</span>
                <span wire:loading wire:target="authenticate">Вход…</span>
            </button>
        </form>
    </main>

</div>
