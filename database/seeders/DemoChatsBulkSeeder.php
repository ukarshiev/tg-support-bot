<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BotUser;
use App\Models\Message;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Bulk demo data for the «Чаты» workspace dialog list and pagination.
 *
 * Creates 100 bot users, each with 100 text messages (10 000 total), across
 * mixed platforms, with some conversations marked closed and varied last-activity
 * times so the dialog list, the open/closed filter, and sorting all have data.
 *
 * NOT wired into DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\DemoChatsBulkSeeder
 *
 * Re-running it wipes the demo range (chat_id 880000000..880000099) and its
 * messages (FK cascade), then regenerates everything.
 */
class DemoChatsBulkSeeder extends Seeder
{
    /** Base chat id for the demo range (unsigned bigint). */
    private const CHAT_ID_BASE = 880000000;

    /** Support/bot id used as the counterpart in from_id/to_id. */
    private const SUPPORT_ID = 100000002;

    /** Number of chats to create. */
    private const CHATS = 100;

    /** Messages per chat. */
    private const MESSAGES_PER_CHAT = 100;

    /** Platforms cycled across the demo chats. */
    private const PLATFORMS = ['telegram', 'vk', 'max'];

    public function run(): void
    {
        // Idempotent: drop the whole demo range (messages cascade via FK).
        BotUser::whereBetween('chat_id', [self::CHAT_ID_BASE, self::CHAT_ID_BASE + self::CHATS - 1])->delete();

        $userTexts = $this->userTexts();
        $supportTexts = $this->supportTexts();
        $uCount = count($userTexts);
        $sCount = count($supportTexts);

        $rows = [];
        $totalMessages = 0;

        for ($c = 0; $c < self::CHATS; $c++) {
            $chatId = self::CHAT_ID_BASE + $c;
            $platform = self::PLATFORMS[$c % count(self::PLATFORMS)];

            $botUser = BotUser::create([
                'chat_id' => $chatId,
                'platform' => $platform,
                'topic_id' => 5000 + $c,
                'is_banned' => false,
                // ~20% of conversations are closed.
                'is_closed' => $c % 5 === 0,
            ]);

            // Varied last-activity per chat → mixed ordering in the dialog list.
            $cursor = Carbon::now()
                ->subDays(random_int(0, 14))
                ->subMinutes(random_int(0, 1440));

            $u = 0;
            $s = 0;
            $count = 0;

            while ($count < self::MESSAGES_PER_CHAT) {
                $userBurst = random_int(1, 2);
                for ($i = 0; $i < $userBurst && $count < self::MESSAGES_PER_CHAT; $i++) {
                    $cursor = $cursor->copy()->addSeconds(random_int(120, 2400));
                    $rows[] = $this->row($botUser->id, $chatId, 'incoming', $userTexts[$u % $uCount], $cursor);
                    $u++;
                    $count++;
                }

                $supportBurst = random_int(1, 2);
                for ($i = 0; $i < $supportBurst && $count < self::MESSAGES_PER_CHAT; $i++) {
                    $cursor = $cursor->copy()->addSeconds(random_int(120, 2400));
                    $rows[] = $this->row($botUser->id, $chatId, 'outgoing', $supportTexts[$s % $sCount], $cursor);
                    $s++;
                    $count++;
                }
            }

            $totalMessages += $count;

            // Flush periodically to keep memory flat.
            if (count($rows) >= 1000) {
                Message::insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Message::insert($rows);
        }

        $this->command->info(sprintf(
            'DemoChatsBulkSeeder: %d chats + %d messages.',
            self::CHATS,
            $totalMessages,
        ));
    }

    /**
     * Build one message row for bulk insert.
     *
     * @param int    $botUserId
     * @param int    $chatId
     * @param string $type      'incoming'|'outgoing'
     * @param string $text
     * @param Carbon $at
     *
     * @return array<string, mixed>
     */
    private function row(int $botUserId, int $chatId, string $type, string $text, Carbon $at): array
    {
        $incoming = $type === 'incoming';

        return [
            'bot_user_id' => $botUserId,
            'platform' => 'telegram',
            'message_type' => $type,
            'from_id' => $incoming ? $chatId : self::SUPPORT_ID,
            'to_id' => $incoming ? self::SUPPORT_ID : $chatId,
            'text' => $text,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * Incoming (user) messages of varied formats.
     *
     * @return list<string>
     */
    private function userTexts(): array
    {
        return [
            'Здравствуйте! 👋',
            'Не проходит оплата картой 😟',
            'Не приходит SMS-код, что делать?',
            "Опишу подробнее:\n1. Оформил заказ\n2. Списались деньги\n3. Статус «не оплачен»",
            'Спасибо большое! 🙏',
            'Ок',
            'Ссылка на заказ: https://shop.example.com/orders/12345',
            'Номер заказа #А-2024-00731',
            'А когда доставка? Жду 📦',
            'Можно вернуть товар?',
            'не работает приложение, белый экран',
            'ПОЧЕМУ ТАК ДОЛГО???',
            'Подскажите часы работы 🕐',
            "Вопросы:\n— смена тарифа\n— возврат средств",
            'ага, понял, спасибо 👍',
            'Можно счёт для юр.лица? ИНН 7701234567',
        ];
    }

    /**
     * Outgoing (support) messages of varied formats.
     *
     * @return list<string>
     */
    private function supportTexts(): array
    {
        return [
            'Здравствуйте! Чем могу помочь? 🙂',
            'Подскажите номер заказа, пожалуйста.',
            'Проверяю, одну минуту ⏳',
            "Выполните, пожалуйста:\n1. Откройте «Настройки»\n2. «Безопасность»\n3. «Сбросить сессию»",
            'С какого устройства вы заходите?',
            'Спасибо за ожидание! Передал специалисту.',
            'Инструкция по возврату: https://help.example.com/returns',
            'Заявка №7731 создана, до 24 часов.',
            'Деньги вернутся в течение 3–5 дней 💳',
            'Понимаю, уже разбираемся 🙏',
            'Готово! Обновил данные в профиле.',
            "Часы работы:\nПн–Пт 9:00–21:00\nСб–Вс 10:00–18:00",
            'Пришлите скриншот ошибки, пожалуйста.',
            'Счёт для юр.лица отправлен на email ✅',
            'Пожалуйста! Обращайтесь 😊',
            'Заказ передан в доставку, ждите курьера 📦',
        ];
    }
}
