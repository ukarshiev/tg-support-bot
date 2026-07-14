<?php

namespace App\Services;

use App\Models\BotUser;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Max\Jobs\SendMaxMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;

class LanguageSelectionService
{
    public function __construct(private readonly SupportLanguageService $languages)
    {
    }

    public function isMenuCommand(?string $text): bool
    {
        return in_array(mb_strtolower(trim((string) $text)), ['/start', '/lang', '/language'], true);
    }

    public function callbackData(array|string|null $payload): ?string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? ($decoded['command'] ?? null) : $payload;
        }

        return $payload['command'] ?? $payload['payload'] ?? null;
    }

    public function handleCallback(BotUser $botUser, array|string|null $payload): bool
    {
        $callback = $this->callbackData($payload);
        if ($this->languages->isPageCallback($callback)) {
            $this->sendSelector($botUser, $this->languages->pageFromCallback($callback));
            return true;
        }

        $code = $this->languages->codeFromCallback($callback);
        $language = $this->languages->find($code);
        if ($code === null || $language === null) {
            return false;
        }

        $botUser->update([
            'preferred_language_code' => $code,
            'preferred_language_name' => $language['name'],
            'preferred_language_selected_at' => now(),
        ]);
        $greeting = $this->languages->greeting($code, $botUser->fresh());
        if (is_string($greeting) && $greeting !== '') {
            $this->sendText($botUser, $greeting);
        }

        return true;
    }

    public function sendSelector(BotUser $botUser, int $page = 1): void
    {
        $keyboard = $this->languages->keyboard($page);
        if ($botUser->platform === 'vk') {
            $rows = array_map(fn (array $row) => array_map(fn (array $button) => [
                'action' => [
                    'type' => 'callback',
                    'label' => $button['text'],
                    'payload' => json_encode(['command' => $button['callback_data']], JSON_UNESCAPED_UNICODE),
                ],
            ], $row), $keyboard);
            SendVkSimpleMessageJob::dispatch(VkTextMessageDto::from([
                'methodQuery' => 'messages.send', 'peer_id' => $botUser->chat_id,
                'message' => $this->languages->prompt($page, $botUser->preferred_language_code),
                'keyboard' => json_encode(['inline' => true, 'buttons' => $rows], JSON_UNESCAPED_UNICODE),
            ]));
            return;
        }

        $rows = array_map(fn (array $row) => array_map(fn (array $button) => [
            'type' => 'callback', 'text' => $button['text'], 'payload' => $button['callback_data'],
        ], $row), $keyboard);
        SendMaxMessageJob::dispatch($botUser->id, null, MaxTextMessageDto::from([
            'methodQuery' => 'sendMessage', 'user_id' => $botUser->chat_id,
            'text' => $this->languages->prompt($page, $botUser->preferred_language_code), 'keyboard' => $rows,
        ]));
    }

    private function sendText(BotUser $botUser, string $text): void
    {
        if ($botUser->platform === 'vk') {
            SendVkSimpleMessageJob::dispatch(VkTextMessageDto::from([
                'methodQuery' => 'messages.send', 'peer_id' => $botUser->chat_id, 'message' => $text,
            ]));
            return;
        }

        SendMaxMessageJob::dispatch($botUser->id, null, MaxTextMessageDto::from([
            'methodQuery' => 'sendMessage', 'user_id' => $botUser->chat_id, 'text' => $text,
        ]));
    }
}
