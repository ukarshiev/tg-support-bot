<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services\Commands;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class AutoAiModeCommand
{
    private const GENERAL_THREAD_ID = 1;

    public function handleIfCommand(TelegramUpdateDto $update): bool
    {
        $action = $this->parseAction((string) $update->text);
        if ($action === null) {
            return false;
        }

        if (! $this->isGeneralSupportThread($update)) {
            if ($this->isSupportGroupMessage($update)) {
                $this->sendThreadReply(
                    $update->messageThreadId,
                    'Команда Auto AI доступна только в теме General.'
                );

                return true;
            }

            return false;
        }

        $settings = app(SettingsService::class);
        $isAdmin = $this->isGroupAdmin($update, (string) $settings->get('telegram.token'));
        if (! $isAdmin) {
            $this->sendGeneralReply('Команда доступна только администраторам группы.');
            Log::channel('app')->warning('AutoAiModeCommand: denied for non-admin', [
                'source' => 'auto_ai_command_denied',
                'telegram_user_id' => $this->telegramUserId($update),
            ]);

            return true;
        }

        if ($action === 'on') {
            $settings->set('ai.auto_reply', true);
            $this->sendGeneralReply('Auto AI: ON — AI отвечает клиентам сам.');
            return true;
        }

        if ($action === 'off') {
            $settings->set('ai.auto_reply', false);
            $this->sendGeneralReply('Auto AI: OFF — AI пишет только внутренние подсказки.');
            return true;
        }

        $enabled = (bool) $settings->get('ai.auto_reply');
        $this->sendGeneralReply(
            $enabled
            ? 'Auto AI: ON — AI отвечает клиентам сам.'
            : 'Auto AI: OFF — AI пишет только внутренние подсказки.'
        );

        return true;
    }

    private function isGeneralSupportThread(TelegramUpdateDto $update): bool
    {
        if (! $this->isSupportGroupMessage($update)) {
            return false;
        }

        if ($update->messageThreadId === null) {
            return true;
        }

        return (int) $update->messageThreadId === self::GENERAL_THREAD_ID;
    }

    private function isSupportGroupMessage(TelegramUpdateDto $update): bool
    {
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        return $update->typeQuery === 'message'
            && $update->typeSource === 'supergroup'
            && (string) $update->chatId === $groupId;
    }

    private function isGroupAdmin(TelegramUpdateDto $update, string $token): bool
    {
        $userId = $this->telegramUserId($update);
        if ($userId === null || $token === '') {
            return false;
        }

        $response = TelegramMethods::sendQueryTelegram('getChatMember', [
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'user_id' => $userId,
        ], $token);

        if ($response->ok !== true) {
            return false;
        }

        $status = (string) ($response->rawData['result']['status'] ?? '');

        return in_array($status, ['creator', 'administrator'], true);
    }

    private function sendGeneralReply(string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'token' => (string) app(SettingsService::class)->get('telegram.token'),
            'typeSource' => 'supergroup',
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }

    private function sendThreadReply(?int $messageThreadId, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'token' => (string) app(SettingsService::class)->get('telegram.token'),
            'typeSource' => 'supergroup',
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $messageThreadId,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }

    private function telegramUserId(TelegramUpdateDto $update): ?int
    {
        $id = $update->rawData['message']['from']['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function parseAction(string $text): ?string
    {
        $normalized = trim(mb_strtolower($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        $isCommand = preg_match('/^(?:\/autoai(?:@\w+)?|autoai)(?:\s+(on|off|status))?$/u', $normalized, $matches);
        if ($isCommand !== 1) {
            return null;
        }

        $action = $matches[1] ?? 'status';

        return in_array($action, ['on', 'off', 'status'], true) ? $action : null;
    }
}
