<?php

use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        if (AutoReply::where('type', AutoReply::TYPE_WELCOME)->exists()) {
            return;
        }

        $ruGreeting = (string) config('support_languages.languages.ru.greeting', 'Добрый день! Чем я могу вам помочь?');
        $hash = AutoReply::sourceHash($ruGreeting);

        $welcomeId = DB::table('auto_replies')->insertGetId([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => '__system_welcome__',
            'response' => $ruGreeting,
            'source_locale' => 'ru',
            'source_hash' => $hash,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ((array) config('support_languages.languages', []) as $code => $language) {
            if ($code === 'ru') {
                continue;
            }

            DB::table('auto_reply_translations')->insert([
                'auto_reply_id' => $welcomeId,
                'locale' => (string) $code,
                'text' => (string) ($language['greeting'] ?? ''),
                'status' => AutoReplyTranslation::STATUS_READY,
                'source' => AutoReplyTranslation::SOURCE_MANUAL,
                'provider' => 'legacy_config',
                'source_hash' => $hash,
                'translated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        AutoReply::where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', '__system_welcome__')
            ->delete();
    }
};
