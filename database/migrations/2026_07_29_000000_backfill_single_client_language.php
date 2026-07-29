<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('bot_users', 'chat_translation_locale')) {
            return;
        }

        DB::table('bot_users')
            ->select([
                'id',
                'preferred_language_code',
                'preferred_language_name',
                'preferred_language_selected_at',
                'chat_translation_locale',
                'chat_translation_locale_selected_at',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $chatCode = strtolower(trim((string) $user->chat_translation_locale));
                    if ($chatCode === '' || $user->chat_translation_locale_selected_at === null) {
                        continue;
                    }

                    $chatSelectedAt = strtotime((string) $user->chat_translation_locale_selected_at);
                    $preferredSelectedAt = $user->preferred_language_selected_at === null
                        ? false
                        : strtotime((string) $user->preferred_language_selected_at);

                    if ($preferredSelectedAt !== false && $chatSelectedAt < $preferredSelectedAt) {
                        continue;
                    }

                    DB::table('bot_users')->where('id', $user->id)->update([
                        'preferred_language_code' => $chatCode,
                        'preferred_language_name' => $user->preferred_language_code === $chatCode
                            ? $user->preferred_language_name
                            : null,
                        'preferred_language_selected_at' => $user->chat_translation_locale_selected_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed. Legacy columns remain in
        // place for one compatibility release so the previous image can roll back.
    }
};
