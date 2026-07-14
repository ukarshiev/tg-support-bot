<div class="flex items-end w-full" style="gap:10px;">
    <div
        class="flex shrink-0 items-center justify-center rounded-full text-white font-semibold select-none"
        style="width:32px; height:32px; background:{{ $hdrColor }}; font-size:11px;"
        aria-hidden="true"
    >{{ $hdrInitials }}</div>
    <div
        class="flex flex-col"
        style="border-radius:16px 16px 16px 4px; background:var(--color-chat-bubble-incoming); border:1px solid var(--color-chat-bubble-incoming-border); padding:10px 14px; gap:4px; max-width:70%;"
        title="Краткая контактная информация"
    >
        <p class="text-sm text-text-primary" style="font-size:14px; line-height:1.4; white-space:pre-wrap; overflow-wrap:anywhere;">{{ $this->contactSummaryText() }}</p>
    </div>
</div>
