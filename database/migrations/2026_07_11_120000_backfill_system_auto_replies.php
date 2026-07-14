<?php

use App\Models\AutoReply;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /** @var array<string, string> */
    private const RESPONSES = [
        AutoReply::TYPE_WELCOME => 'Добрый день! Чем я могу вам помочь?',
        AutoReply::TYPE_DIALOG_CLOSED => 'Ваше обращение закрыто!',
        AutoReply::TYPE_FEEDBACK_REQUEST => 'Пожалуйста, оцените качество нашей поддержки:',
        AutoReply::TYPE_FEEDBACK_THANK_YOU => 'Спасибо за отзыв! Ваша оценка принята.',
        AutoReply::TYPE_BAN => '⛔️ Вы были заблокированы по решению администрации бота!',
    ];

    public function up(): void
    {
        foreach (self::RESPONSES as $type => $response) {
            $trigger = AutoReply::systemTriggers()[$type];

            if (DB::table('auto_replies')->where('type', $type)->where('trigger', $trigger)->exists()) {
                continue;
            }

            DB::table('auto_replies')->insert([
                'type' => $type,
                'trigger' => $trigger,
                'response' => $response,
                'source_locale' => 'ru',
                'source_hash' => AutoReply::sourceHash($response),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Пользователь мог отредактировать шаблоны и переводы после миграции.
        // Сохраняем данные, чтобы откат кода не привёл к их потере.
    }
};
