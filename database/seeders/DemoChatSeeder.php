<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BotUser;
use App\Models\Message;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data for the «Чаты» workspace (/admin/chats).
 *
 * Creates a single Telegram bot user and 500 text messages of varied formats
 * (short, long, multi-line, emoji, links, lists, questions, …) in a natural
 * back-and-forth, spread over the last ~14 days so the message thread shows
 * date dividers and the dialog list sorts realistically.
 *
 * This seeder is NOT wired into DatabaseSeeder — run it explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\DemoChatSeeder
 *
 * Re-running it wipes the demo user's previous messages and regenerates them.
 */
class DemoChatSeeder extends Seeder
{
    /** Demo Telegram user chat id (unsigned bigint). */
    private const CHAT_ID = 770000777;

    /** Support/bot id used as the counterpart in from_id/to_id. */
    private const SUPPORT_ID = 100000001;

    /** Total number of messages to generate. */
    private const MESSAGE_COUNT = 500;

    public function run(): void
    {
        $botUser = BotUser::firstOrCreate(
            ['chat_id' => self::CHAT_ID, 'platform' => 'telegram'],
            ['topic_id' => 4242, 'is_banned' => false, 'is_closed' => false],
        );

        // Idempotent: clear previous demo messages for this user.
        Message::where('bot_user_id', $botUser->id)->delete();

        $userTexts = $this->userTexts();
        $supportTexts = $this->supportTexts();

        // Spread messages over the last ~14 days, strictly increasing.
        $cursor = Carbon::now()->subDays(14)->setTime(9, 0);

        $rows = [];
        $count = 0;
        $u = 0;   // rotating index into the user pool
        $s = 0;   // rotating index into the support pool

        while ($count < self::MESSAGE_COUNT) {
            // One exchange: 1–2 user messages, then 1–2 support replies.
            $userBurst = random_int(1, 2);
            for ($i = 0; $i < $userBurst && $count < self::MESSAGE_COUNT; $i++) {
                $cursor = $cursor->copy()->addSeconds(random_int(60, 5400));
                $rows[] = $this->row($botUser->id, 'incoming', $userTexts[$u % count($userTexts)], $cursor);
                $u++;
                $count++;
            }

            $supportBurst = random_int(1, 2);
            for ($i = 0; $i < $supportBurst && $count < self::MESSAGE_COUNT; $i++) {
                $cursor = $cursor->copy()->addSeconds(random_int(60, 5400));
                $rows[] = $this->row($botUser->id, 'outgoing', $supportTexts[$s % count($supportTexts)], $cursor);
                $s++;
                $count++;
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            Message::insert($chunk);
        }

        $this->command->info(sprintf(
            'DemoChatSeeder: bot_user #%d (chat_id %d) + %d messages.',
            $botUser->id,
            self::CHAT_ID,
            count($rows),
        ));
    }

    /**
     * Build one message row for bulk insert.
     *
     * @param int    $botUserId
     * @param string $type      'incoming'|'outgoing'
     * @param string $text
     * @param Carbon $at
     *
     * @return array<string, mixed>
     */
    private function row(int $botUserId, string $type, string $text, Carbon $at): array
    {
        $incoming = $type === 'incoming';

        return [
            'bot_user_id' => $botUserId,
            'platform' => 'telegram',
            'message_type' => $type,
            'from_id' => $incoming ? self::CHAT_ID : self::SUPPORT_ID,
            'to_id' => $incoming ? self::SUPPORT_ID : self::CHAT_ID,
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
            'У меня не проходит оплата картой, помогите пожалуйста 😟',
            'Не приходит SMS-код для входа. Что делать?',
            "Опишу подробнее, что произошло:\n1. Оформил заказ\n2. Списались деньги\n3. Статус «не оплачен»\nПочему так?",
            'Спасибо большое! 🙏',
            'Ок',
            'Вот ссылка на мой заказ: https://shop.example.com/orders/12345',
            'Номер заказа #А-2024-00731',
            'А когда будет доставка?? Очень жду 📦',
            'Можно ли вернуть товар, если он не подошёл по размеру?',
            'не работает приложение, белый экран после обновления',
            'ПОЧЕМУ ТАК ДОЛГО???',
            'Подскажите часы работы поддержки 🕐',
            "Список вопросов:\n— смена тарифа\n— перенос подписки\n— возврат средств",
            'ага, понял, спасибо 👍',
            'А можно счёт на оплату для юр.лица? ИНН 7701234567',
            'Здравствуйте ещё раз, проблема повторилась сегодня утром',
            'Отправляю скрин ошибки (приложу отдельно)',
            'хорошо, жду ответа',
            'Это срочно, клиент ждёт на линии!',
            'Можно ускорить рассмотрение обращения? Прошло уже 3 дня',
            'Спасибо, всё заработало 🎉',
            'И ещё один вопрос: как поменять email в личном кабинете?',
            'Пишу с другого устройства, на телефоне всё ок, а на ПК нет',
            'окей',
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
            'Здравствуйте! Меня зовут Анна, чем могу помочь? 🙂',
            'Подскажите, пожалуйста, номер вашего заказа.',
            'Проверяю информацию, одну минуту ⏳',
            "Чтобы решить вопрос, выполните, пожалуйста, шаги:\n1. Откройте «Настройки»\n2. Раздел «Безопасность»\n3. Нажмите «Сбросить сессию»",
            'Уточните, пожалуйста, с какого устройства вы заходите?',
            'Спасибо за ожидание! Передал вопрос профильному специалисту.',
            'Подробная инструкция по возврату здесь: https://help.example.com/returns',
            'Заявка №7731 создана. Срок рассмотрения — до 24 часов.',
            'Деньги вернутся на карту в течение 3–5 рабочих дней 💳',
            'К сожалению, по этому тарифу опция недоступна. Предлагаю альтернативу.',
            'Понимаю ваше беспокойство, уже разбираемся 🙏',
            'Готово! Я обновил данные в вашем профиле.',
            "Часы работы поддержки:\nПн–Пт 9:00–21:00\nСб–Вс 10:00–18:00",
            'Можете прислать скриншот ошибки? Так будет быстрее.',
            'Вижу проблему, передаю в технический отдел. Ожидайте, пожалуйста.',
            'Счёт на оплату для юр.лица отправлен на ваш email ✅',
            'Пожалуйста! Обращайтесь, если будут ещё вопросы 😊',
            'Проблема на нашей стороне устранена, попробуйте ещё раз.',
            'Рекомендую очистить кэш браузера и перезайти.',
            'Заказ передан в доставку, ожидайте курьера завтра с 12:00 до 18:00 📦',
            'Всё верно, подтверждаю.',
            'Сейчас уточню у коллег и вернусь с ответом.',
            'Email успешно изменён. Проверьте, пожалуйста, входящие.',
            'Рад, что всё получилось! Хорошего дня 🌟',
        ];
    }
}
