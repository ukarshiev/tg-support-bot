<?php

declare(strict_types=1);

namespace App\Services\AutoReplies;

use App\Models\AutoReplyVariable;
use App\Models\BotUser;
use App\Modules\Telegram\Actions\GetChat;
use Illuminate\Support\Facades\Cache;

class AutoReplyVariableRenderer
{
    /**
     * @return array{0: string, 1: list<string>}
     */
    public function render(string $text, ?BotUser $botUser = null): array
    {
        $warnings = [];

        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', function (array $matches) use (&$warnings): string {
            $key = AutoReplyVariable::normalizeKey((string) $matches[1]);
            $variable = AutoReplyVariable::query()
                ->where('key', $key)
                ->where('enabled', true)
                ->first();

            if ($variable === null) {
                $warnings[] = "Переменная {{$key}} не найдена или выключена.";

                return (string) $matches[0];
            }

            return (string) $variable->value;
        }, $text) ?? $text;

        $rendered = preg_replace_callback(
            '/(?<!\{)\{(id|email|first_name|last_name|username|platform)\}(?!\})/u',
            function (array $matches) use ($botUser, &$warnings): string {
                if ($botUser === null) {
                    $warnings[] = "Переменная {{$matches[1]}} требует выбранного клиента.";

                    return (string) $matches[0];
                }

                $values = $this->clientValues($botUser);

                return $values[$matches[1]];
            },
            $rendered,
        ) ?? $rendered;

        return [$rendered, array_values(array_unique($warnings))];
    }

    /**
     * @return list<string>
     */
    public function usedKeys(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $key): string => AutoReplyVariable::normalizeKey($key),
            $matches[1],
        )));
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string, username: string, platform: string}
     */
    private function clientValues(BotUser $botUser): array
    {
        $profile = [];
        if ($botUser->platform === 'telegram') {
            $profile = Cache::remember(
                "auto-reply:variables:telegram:{$botUser->chat_id}",
                now()->addHour(),
                function () use ($botUser): array {
                    try {
                        $response = app(GetChat::class)->execute((int) $botUser->chat_id);

                        return $response->ok ? (array) ($response->rawData['result'] ?? []) : [];
                    } catch (\Throwable) {
                        return [];
                    }
                },
            );
        }

        return [
            'id' => (string) ($profile['id'] ?? $botUser->chat_id),
            'email' => (string) ($profile['email'] ?? ''),
            'first_name' => (string) ($profile['first_name'] ?? $botUser->display_name ?? ''),
            'last_name' => (string) ($profile['last_name'] ?? ''),
            'username' => (string) ($profile['username'] ?? $botUser->username ?? ''),
            'platform' => (string) $botUser->platform,
        ];
    }
}
