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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramSupportFlowCheck extends Command
{
    private const LOCK_KEY = 'telegram:support-flow-check:lock';

    private const LOCK_RELEASE_MARGIN_SECONDS = 60;

    private const DELIVERY_BACKOFF_SECONDS = [2, 5, 10, 20];

    private const DELIVERY_ATTEMPTS = 5;

    private const TELEGRAM_REQUEST_TIMEOUT_SECONDS = 8;

    private const QUEUE_ALLOWANCE_SECONDS = 15;

    private const DEFAULT_LANGUAGE_PAUSE_MILLISECONDS = 1100;

    private const REPORT_ALLOWANCE_SECONDS = 60;

    private const REPORT_MESSAGE_LIMIT = 4096;

    protected $signature = 'telegram:support-flow-check
        {--chat-id= : Telegram chat_id служебного диалога}
        {--languages= : Языки через запятую; без опции проверяются все включённые}
        {--await-timeout= : Ожидание подтверждения каждого шага, секунд}
        {--language-pause= : Пауза между языками, миллисекунд}
        {--deadline= : Максимальная длительность всего прогона, секунд}
        {--no-queue-drain : Не запускать queue:work --once после шагов}';

    protected $description = 'Проверяет служебный Telegram-диалог: /start, /lang, выбор всех включённых языков и welcome';

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

        $languageCodes = $this->languageCodes($settings, $languages);
        if ($languageCodes === []) {
            $this->error('Telegram support flow check failed: no enabled support languages.');
            return Command::FAILURE;
        }

        try {
            $awaitTimeoutSeconds = $this->integerOptionOrSetting(
                'await-timeout',
                'telegram.health_check_await_timeout_seconds',
                $settings,
                $this->defaultAwaitTimeoutSeconds(),
                1,
            );
            $languagePauseMilliseconds = $this->integerOptionOrSetting(
                'language-pause',
                'telegram.health_check_language_pause_milliseconds',
                $settings,
                self::DEFAULT_LANGUAGE_PAUSE_MILLISECONDS,
                0,
            );
            $runDeadlineSeconds = $this->integerOptionOrSetting(
                'deadline',
                'telegram.health_check_deadline_seconds',
                $settings,
                $this->defaultRunDeadlineSeconds(
                    count($languageCodes),
                    $awaitTimeoutSeconds,
                    $languagePauseMilliseconds,
                ),
                1,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return Command::FAILURE;
        }

        $lock = Cache::lock(self::LOCK_KEY, $runDeadlineSeconds + self::LOCK_RELEASE_MARGIN_SECONDS);
        if (!$lock->get()) {
            $this->info('Telegram support flow check skipped: another run is still active.');
            return Command::SUCCESS;
        }

        try {
            return $this->runCheck(
                $chatId,
                $languageCodes,
                $awaitTimeoutSeconds,
                $languagePauseMilliseconds,
                $runDeadlineSeconds,
                $settings,
                $languages,
                $sendStartMessage,
                $selectLanguage,
                $markupSanitizer,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $languageCodes
     */
    private function runCheck(
        string $chatId,
        array $languageCodes,
        int $awaitTimeoutSeconds,
        int $languagePauseMilliseconds,
        int $runDeadlineSeconds,
        SettingsService $settings,
        SupportLanguageService $languages,
        SendStartMessage $sendStartMessage,
        SelectLanguage $selectLanguage,
        TelegramMarkupSanitizer $markupSanitizer,
    ): int {
        $botUser = BotUser::firstOrCreate(
            ['chat_id' => (int) $chatId],
            ['platform' => 'telegram', 'display_name' => 'Support Flow Check', 'username' => 'support_flow_check'],
        );
        $originalLanguage = $botUser->only([
            'preferred_language_code',
            'preferred_language_name',
            'preferred_language_selected_at',
        ]);

        $startedAt = now();
        $runDeadline = microtime(true) + $runDeadlineSeconds;
        $checks = [];
        $baseMessageId = (int) (now()->timestamp % 1000000) * 10;

        try {
            if ($this->hasFullStepBudget($runDeadline, $awaitTimeoutSeconds)) {
                $startUpdate = $this->messageUpdate($botUser, '/start', $baseMessageId);
                $sendStartMessage->execute($startUpdate);
                $this->drainQueue(2);
                $checks[] = $this->awaitCheck(
                    fn (): array => $this->checkSelectorQueuedOrDelivered($botUser, '/start', $startedAt),
                    $awaitTimeoutSeconds,
                    $runDeadline,
                );
            } else {
                $checks[] = $this->skippedCheck('/start selector');
            }

            if ($this->hasFullStepBudget($runDeadline, $awaitTimeoutSeconds)) {
                $langUpdate = $this->messageUpdate($botUser, '/lang', $baseMessageId + 1);
                $sendStartMessage->force($langUpdate);
                $this->drainQueue(2);
                $checks[] = $this->awaitCheck(
                    fn (): array => $this->checkSelectorQueuedOrDelivered($botUser, '/lang', $startedAt),
                    $awaitTimeoutSeconds,
                    $runDeadline,
                );
            } else {
                $checks[] = $this->skippedCheck('/lang selector');
            }

            foreach ($languageCodes as $index => $code) {
                if (!$this->hasFullStepBudget($runDeadline, $awaitTimeoutSeconds)) {
                    foreach (array_slice($languageCodes, $index) as $uncheckedCode) {
                        $checks[] = $this->skippedCheck("select {$uncheckedCode}", $uncheckedCode);
                    }
                    break;
                }

                $language = $languages->find($code);
                if ($language === null) {
                    $checks[] = [
                        'ok' => false,
                        'status' => 'failed',
                        'step' => "select {$code}",
                        'detail' => 'язык не найден среди доступных Telegram-языков',
                        'language_code' => $code,
                    ];
                    continue;
                }

                Cache::forget(sprintf('telegram:language-flow:%d:%s', $botUser->id, $code));
                $greeting = $markupSanitizer->toPlainText($languages->greeting($code, $botUser));

                $selectLanguage->execute(
                    $botUser->refresh(),
                    $this->callbackUpdate($botUser, $baseMessageId + 100 + $index, "select_language:{$code}", $code),
                );
                $this->drainQueue(3);

                $check = $this->awaitCheck(
                    fn (): array => $this->checkWelcomeDelivered($botUser->refresh(), $code, $language['name'], $greeting, $startedAt),
                    $awaitTimeoutSeconds,
                    $runDeadline,
                );
                $check['language_code'] = $code;
                $checks[] = $check;

                if ($index < count($languageCodes) - 1) {
                    $this->pauseBetweenLanguages($languagePauseMilliseconds, $runDeadline);
                }
            }

            $ok = collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed');
            $this->sendReport($botUser->refresh(), $checks, $startedAt, $ok);

            Log::channel('app')->log($ok ? 'info' : 'warning', 'Telegram support flow check finished', [
                'source' => 'telegram_support_flow_check',
                'bot_user_id' => $botUser->id,
                'chat_id' => $botUser->chat_id,
                'ok' => $ok,
                'checks' => $checks,
                'await_timeout_seconds' => $awaitTimeoutSeconds,
                'language_pause_milliseconds' => $languagePauseMilliseconds,
                'run_deadline_seconds' => $runDeadlineSeconds,
            ]);

            foreach ($checks as $check) {
                $prefix = match ($check['status']) {
                    'passed' => 'OK ',
                    'skipped' => 'SKIP ',
                    default => 'FAIL ',
                };
                $this->line($prefix . $check['step'] . ' — ' . $check['detail']);
            }

            return $ok ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $botUser->update($originalLanguage);
            $botUser->refresh();
        }
    }

    /**
     * @return list<string>
     */
    private function languageCodes(SettingsService $settings, SupportLanguageService $languages): array
    {
        $option = trim((string) ($this->option('languages') ?? ''));
        $configured = $option !== ''
            ? explode(',', $option)
            : $settings->get('telegram.health_check_languages');
        $enabled = array_keys($languages->all());

        // Пустая отдельная настройка означает «проверять весь актуальный список»,
        // поэтому новые включённые языки автоматически попадают в ночной canary.
        if (!is_array($configured) || $configured === []) {
            return $enabled;
        }

        return collect($configured)
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn ($code): bool => $code !== '' && in_array($code, $enabled, true))
            ->unique()
            ->values()
            ->all();
    }

    private function integerOptionOrSetting(
        string $option,
        string $setting,
        SettingsService $settings,
        int $default,
        int $minimum,
    ): int {
        $optionValue = $this->option($option);
        $value = $optionValue !== null ? $optionValue : $settings->get($setting, $default);

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum) {
            throw new \InvalidArgumentException(
                "Telegram support flow check failed: {$option}/{$setting} must be an integer >= {$minimum}.",
            );
        }

        return (int) $value;
    }

    private function defaultAwaitTimeoutSeconds(): int
    {
        // Пять сетевых попыток могут занять по 8 секунд каждая. Добавляем все
        // backoff (2+5+10+20) и 15 секунд на ожидание очереди: 40+37+15=92.
        return (self::DELIVERY_ATTEMPTS * self::TELEGRAM_REQUEST_TIMEOUT_SECONDS)
            + array_sum(self::DELIVERY_BACKOFF_SECONDS)
            + self::QUEUE_ALLOWANCE_SECONDS;
    }

    private function defaultRunDeadlineSeconds(
        int $languageCount,
        int $awaitTimeoutSeconds,
        int $languagePauseMilliseconds,
    ): int {
        $checkBudget = ($languageCount + 2) * $awaitTimeoutSeconds;
        $pauseBudget = (int) ceil(max(0, $languageCount - 1) * $languagePauseMilliseconds / 1000);

        return $checkBudget + $pauseBudget + self::REPORT_ALLOWANCE_SECONDS;
    }

    private function hasFullStepBudget(float $runDeadline, int $awaitTimeoutSeconds): bool
    {
        return ($runDeadline - microtime(true)) >= $awaitTimeoutSeconds;
    }

    private function pauseBetweenLanguages(int $milliseconds, float $runDeadline): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        // Telegram допускает примерно одно сообщение в секунду в один чат.
        // 1100 мс по умолчанию оставляет небольшой запас на джиттер планировщика.
        $remainingMicroseconds = max(0, (int) (($runDeadline - microtime(true)) * 1_000_000));
        usleep(min($milliseconds * 1000, $remainingMicroseconds));
    }

    private function drainQueue(int $ticks): void
    {
        // В sync-режиме job уже выполнена, в Redis-режиме результат ожидает
        // awaitCheck(). Запуск второго worker здесь нарушил бы изоляцию Horizon.
    }

    private function awaitCheck(callable $check, int $timeoutSeconds, float $runDeadline): array
    {
        $deadline = min($runDeadline, microtime(true) + $timeoutSeconds);
        do {
            $result = $check();
            if ($result['ok']) {
                $result['status'] = 'passed';
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $result['status'] = 'failed';
        return $result;
    }

    private function skippedCheck(string $step, ?string $languageCode = null): array
    {
        return [
            'ok' => false,
            'status' => 'skipped',
            'step' => $step,
            'detail' => 'не проверено: исчерпан общий дедлайн прогона',
            'language_code' => $languageCode,
        ];
    }

    private function checkSelectorQueuedOrDelivered(BotUser $botUser, string $command, Carbon $startedAt): array
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
                || app(SupportLanguageService::class)->isSelectorText($message->text));

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
        Carbon $startedAt,
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

    private function sendReport(BotUser $botUser, array $checks, Carbon $startedAt, bool $ok): void
    {
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($groupId === '' || empty($botUser->topic_id)) {
            return;
        }

        foreach ($this->reportMessages($checks, $startedAt, $ok) as $text) {
            TelegramMethods::sendQueryTelegram('sendMessage', [
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'text' => $text,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function reportMessages(array $checks, Carbon $startedAt, bool $ok): array
    {
        $passed = collect($checks)->where('status', 'passed')->pluck('step')->all();
        $failed = collect($checks)->where('status', 'failed');
        $skipped = collect($checks)->where('status', 'skipped');
        $uncheckedLanguages = $skipped->pluck('language_code')->filter()->values()->all();

        $lines = [
            ($ok ? '✅' : '❌') . ' Служебная проверка Telegram-flow',
            'Старт: ' . $startedAt->format('d.m.Y H:i:s'),
            'Итог: успешно ' . count($passed) . ', ошибок ' . $failed->count() . ', не проверено ' . $skipped->count(),
        ];

        if ($passed !== []) {
            $lines[] = '✅ Успешно: ' . implode(', ', $passed);
        }
        foreach ($failed as $check) {
            $lines[] = '❌ ' . $check['step'] . ' — ' . $check['detail'];
        }
        if ($uncheckedLanguages !== []) {
            $lines[] = '⏭ Не проверены из-за общего дедлайна: ' . implode(', ', $uncheckedLanguages);
        }
        foreach ($skipped->whereNull('language_code') as $check) {
            $lines[] = '⏭ ' . $check['step'] . ' — ' . $check['detail'];
        }

        return $this->splitReportLines($lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function splitReportLines(array $lines): array
    {
        $messages = [];
        $current = '';

        foreach ($lines as $line) {
            do {
                if (mb_strlen($current) >= self::REPORT_MESSAGE_LIMIT) {
                    $messages[] = $current;
                    $current = '';
                }

                $separator = $current === '' ? '' : "\n";
                $available = max(
                    0,
                    self::REPORT_MESSAGE_LIMIT - mb_strlen($current) - mb_strlen($separator),
                );
                if ($available <= 0) {
                    if ($current !== '') {
                        $messages[] = $current;
                    }
                    $current = '';
                    continue;
                }

                $part = mb_substr($line, 0, $available);
                $current .= $separator . $part;
                $line = mb_substr($line, mb_strlen($part));

                if (mb_strlen($current) >= self::REPORT_MESSAGE_LIMIT) {
                    $messages[] = $current;
                    $current = '';
                }
            } while ($line !== '');
        }

        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
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
