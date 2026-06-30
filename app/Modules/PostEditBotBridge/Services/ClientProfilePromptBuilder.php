<?php

namespace App\Modules\PostEditBotBridge\Services;

class ClientProfilePromptBuilder
{
    /**
     * @param array<string, mixed>|null $profile
     */
    public function build(?array $profile): ?string
    {
        if ($profile === null) {
            return null;
        }

        if (($profile['found'] ?? false) !== true) {
            $notes = implode('; ', array_filter((array) ($profile['notes'] ?? [])));
            return "Профиль клиента в PostEditBot не найден. Не выдумывай подписки, оплаты и доступы. {$notes}";
        }

        $client = is_array($profile['client'] ?? null) ? $profile['client'] : [];
        $active = $this->count($profile['currentSubscriptions'] ?? []);
        $past = $this->count($profile['pastSubscriptions'] ?? []);
        $payments = $this->count($profile['payments'] ?? []);

        return implode("\n", array_filter([
            'Данные клиента из PostEditBot:',
            '- ID клиента: ' . ($client['id'] ?? 'не указан'),
            '- Telegram ID: ' . ($client['tgId'] ?? 'не указан'),
            '- Username: ' . ($client['tgUsername'] ?? 'не указан'),
            '- Email: ' . ($client['email'] ?? 'не указан'),
            "- Активные/отложенные подписки: {$active}",
            "- Прошлые подписки: {$past}",
            "- Последние платежи: {$payments}",
            'Правила: не обещай возвраты, разблокировки и продление доступа без оператора; если данных не хватает — эскалируй.',
        ]));
    }

    private function count(mixed $value): int
    {
        return is_countable($value) ? count($value) : 0;
    }
}
