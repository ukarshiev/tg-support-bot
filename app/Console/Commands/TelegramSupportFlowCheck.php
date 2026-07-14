<?php

namespace App\Console\Commands;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Actions\SelectLanguage;
use App\Modules\Telegram\Actions\SendStartMessage;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Translation\Support\TelegramMarkupSanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramSupportFlowCheck extends Command
{
    protected $signature = 'telegram:support-flow-check
        {--chat-id= : Telegram chat_id служебного диалога}
        {--languages= : Языки через запятую, например pl,en,ar}
        {--no-queue-drain : Не запускать queue:work --once после шагов}';

    protected $description = 'Проверяет служебный Telegram-диалог: /start, /lang, страницы языков, выбор языков и welcome';

    public function handle(
        SettingsService $settings,
        SupportLanguageService $languages,
        SendStartMessage $sendStartMessage,
        SelectLanguage $selectLanguage,
        TelegramMarkupSanitizer $markupSanitizer,
    ): int {
        $enabled = (bool) $settings->get('telegram.health_check_enabled', false);
        $chatId = (string) ($this->option('chat-id') ?: $settings->get('telegram.health_check_chat_id', ''));

        if (!$enabled && $this->option('chat-id') === null) {
            $this->info('Telegram support flow check skipped: telegram.health_check_enabled=false.');
            return Command::SUCCESS;
        }

        if ($chatId === '') {
            $this->error('Telegram support flow check failed: telegram.health_check_chat_id is empty.');
            return Command::FAILURE;
        }

        $botUser = BotUser::firstOrCreate(
            ['chat_id' => (int) $chatId],
            ['platform' => 'telegram', 'display_name' => 'Support Flow Check', 'username' => 'support_flow_check'],
        );
        $originalLanguage = $botUser->only([
            'preferred_language_code',
            'preferred_language_name',
            'preferred_language_selected_at',
        ]);

        $languageCodes = $this->languageCodes($settings, $languages);
        if ($languageCodes === []) {
            $this->error('Telegram support flow check failed: no enabled support languages.');
            return Command::FAILURE;
        }

        $startedAt = now();

        $checks = [];
        $baseMessageId = (int) (now()->timestamp % 1000000) * 10;

        $startUpdate = $this->messageUpdate($botUser, '/start', $baseMessageId);
        $sendStartMessage->execute($startUpdate);
        $this->drainQueue(2);
        $checks[] = $this->awaitCheck(fn (): array => $this->checkSelectorQueuedOrDelivered($botUser, '/start', $startedAt));

        $langUpdate = $this->messageUpdate($botUser, '/lang', $baseMessageId + 1);
        $sendStartMessage->force($langUpdate);
        $this->drainQueue(2);
        $checks[] = $this->awaitCheck(fn (): array => $this->checkSelectorQueuedOrDelivered($botUser, '/lang', $startedAt));

        foreach ($languageCodes as $index => $code) {
            $language = $languages->find($code);
            if ($language === null) {
                $checks[] = ['ok' => false, 'step' => "select {$code}", 'detail' => 'язык не найден'];
                continue;
            }

            Cache::forget(sprintf('telegram:language-flow:%d:%s', $botUser->id, $code));
            $greeting = $markupSanitizer->toPlainText($languages->greeting($code, $botUser));

            $selectLanguage->execute(
                $botUser->refresh(),
                $this->callbackUpdate($botUser, $baseMessageId + 100 + $index, "select_language:{$code}", $code),
            );
            $this->drainQueue(3);

            $checks[] = $this->awaitCheck(
                fn (): array => $this->checkWelcomeDelivered($botUser->refresh(), $code, $language['name'], $greeting, $startedAt),
            );
        }

        $botUser->update($originalLanguage);
        $botUser->refresh();

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);
        $this->sendReport($botUser->refresh(), $checks, $startedAt, $ok);

        Log::channel('app')->log($ok ? 'info' : 'warning', 'Telegram support flow check finished', [
            'source' => 'telegram_support_flow_check',
            'bot_user_id' => $botUser->id,
            'chat_id' => $botUser->chat_id,
            'ok' => $ok,
            'checks' => $checks,
        ]);

        foreach ($checks as $check) {
            $this->line(($check['ok'] ? 'OK ' : 'FAIL ') . $check['step'] . ' — ' . $check['detail']);
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function languageCodes(SettingsService $settings, SupportLanguageService $languages): array
    {
        $option = (string) ($this->option('languages') ?? '');
        $configured = $option !== ''
            ? explode(',', $option)
            : (array) ($settings->get('telegram.health_check_languages') ?: ['pl', 'en', 'ar']);

        $enabled = array_keys($languages->all());

        return collect($configured)
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn ($code): bool => $code !== '' && in_array($code, $enabled, true))
            ->values()
            ->all();
    }

    private function drainQueue(int $ticks): void
    {
        // В sync-режиме job уже выполнена, в Redis-режиме результат ожидает
        // awaitCheck(). Запуск второго worker здесь нарушил бы изоляцию Horizon.
    }

    private function awaitCheck(callable $check, int $timeoutMilliseconds = 10000): array
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        do {
            $result = $check();
            if ($result['ok']) {
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $result;
    }

    private function checkSelectorQueuedOrDelivered(BotUser $botUser, string $command, \Illuminate\Support\Carbon $startedAt): array
    {
        $columns = Message::supportsStructuralKind() ? ['message_kind', 'text'] : ['text'];
        $exists = Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('platform', $botUser->platform)
            ->where('message_type', 'outgoing')
            ->where('created_at', '>=', $startedAt)
            ->where('to_id', '>', 0)
            ->get($columns)
            ->contains(fn (Message $message): bool => $message->message_kind === Message::KIND_LANGUAGE_SELECTOR
                || app(\App\Modules\Telegram\Services\SupportLanguageService::class)->isSelectorText($message->text));

        return [
            'ok' => $exists,
            'step' => "{$command} selector",
            'detail' => $exists ? 'selector доставлен клиенту' : 'selector не подтверждён в messages.to_id',
        ];
    }

    private function checkWelcomeDelivered(
        BotUser $botUser,
        string $code,
        string $name,
        string $greeting,
        \Illuminate\Support\Carbon $startedAt,
    ): array {
        $exists = Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('platform', $botUser->platform)
            ->where('message_type', 'outgoing')
            ->where('created_at', '>=', $startedAt)
            ->where('text', $greeting)
            ->where('to_id', '>', 0)
            ->exists();

        return [
            'ok' => $exists && $botUser->preferred_language_code === $code,
            'step' => "select {$code}",
            'detail' => $exists
                ? "welcome доставлен, выбран {$name}"
                : "welcome для {$name} не подтверждён в messages.to_id",
        ];
    }

    private function sendReport(BotUser $botUser, array $checks, \Illuminate\Support\Carbon $startedAt, bool $ok): void
    {
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($groupId === '' || empty($botUser->topic_id)) {
            return;
        }

        $lines = collect($checks)
            ->map(fn (array $check): string => ($check['ok'] ? '✅ ' : '❌ ') . $check['step'] . ' — ' . $check['detail'])
            ->implode("\n");

        TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'text' => ($ok ? '✅' : '❌') . " Служебная проверка Telegram-flow\n"
                . 'Старт: ' . $startedAt->format('d.m.Y H:i:s') . "\n"
                . $lines,
        ]);
    }

    private function messageUpdate(BotUser $botUser, string $text, int $messageId): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: $messageId,
            typeQuery: 'message',
            aiTechMessage: false,
            typeSource: 'private',
            isBot: false,
            chatId: (int) $botUser->chat_id,
            messageId: $messageId,
            text: $text,
            username: $botUser->username,
            displayName: $botUser->display_name,
            languageCode: 'ru',
        );
    }

    private function callbackUpdate(BotUser $botUser, int $messageId, string $data, string $languageCode): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: $messageId,
            typeQuery: 'callback_query',
            aiTechMessage: false,
            typeSource: 'private',
            isBot: false,
            chatId: (int) $botUser->chat_id,
            messageId: $messageId,
            text: "Выберите язык / Choose your language:\nСтраница 1/2",
            username: $botUser->username,
            displayName: $botUser->display_name,
            languageCode: $languageCode,
            callbackData: $data,
        );
    }
}
