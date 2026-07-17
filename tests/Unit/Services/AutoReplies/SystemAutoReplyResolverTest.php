<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoReplies;

use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\BotUser;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAutoReplyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_russian_source_and_renders_variables(): void
    {
        $user = $this->user('ru');
        $reply = $this->reply(AutoReply::TYPE_DIALOG_CLOSED);
        $reply->update(['response' => 'Обращение {first_name} закрыто']);

        $text = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_DIALOG_CLOSED, $user);

        $this->assertSame('Обращение Иван закрыто', $text);
    }

    public function test_it_uses_ready_current_translation_for_selected_locale(): void
    {
        $user = $this->user('fr');
        $reply = $this->reply(AutoReply::TYPE_FEEDBACK_REQUEST);
        $this->translation($reply, 'fr', 'Évaluez notre assistance');

        $this->assertSame(
            'Évaluez notre assistance',
            app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_FEEDBACK_REQUEST, $user),
        );
    }

    public function test_it_falls_back_to_ready_english_translation(): void
    {
        $user = $this->user('fr');
        $reply = $this->reply(AutoReply::TYPE_FEEDBACK_THANK_YOU);
        $this->translation($reply, 'en', 'Thanks from database');

        $this->assertSame(
            'Thanks from database',
            app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_FEEDBACK_THANK_YOU, $user),
        );
    }

    public function test_it_rejects_stale_wrong_hash_empty_and_error_translations(): void
    {
        $user = $this->user('fr');
        $reply = $this->reply(AutoReply::TYPE_DIALOG_CLOSED);

        AutoReplyTranslation::create([
            'auto_reply_id' => $reply->id,
            'locale' => 'fr',
            'text' => 'Texte obsolète',
            'status' => AutoReplyTranslation::STATUS_STALE,
            'source_hash' => AutoReply::sourceHash($reply->response),
        ]);
        AutoReplyTranslation::create([
            'auto_reply_id' => $reply->id,
            'locale' => 'en',
            'text' => 'Old English',
            'status' => AutoReplyTranslation::STATUS_READY,
            'source_hash' => 'wrong-hash',
        ]);

        $this->assertSame(
            'Your request has been closed!',
            app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_DIALOG_CLOSED, $user),
        );
    }

    public function test_disabled_or_missing_reply_returns_null(): void
    {
        $user = $this->user('de');
        $this->reply(AutoReply::TYPE_BAN)->update(['enabled' => false]);

        $this->assertNull(app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_BAN, $user));
    }

    public function test_system_backfill_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_07_11_120000_backfill_system_auto_replies.php');

        $migration->up();
        $migration->up();

        foreach ([
            AutoReply::TYPE_WELCOME,
            AutoReply::TYPE_DIALOG_CLOSED,
            AutoReply::TYPE_FEEDBACK_REQUEST,
            AutoReply::TYPE_FEEDBACK_THANK_YOU,
            AutoReply::TYPE_BAN,
        ] as $type) {
            $this->assertSame(1, AutoReply::query()
                ->where('type', $type)
                ->where('trigger', AutoReply::systemTriggers()[$type])
                ->count());
        }
    }

    public function test_system_backfill_repairs_welcome_with_legacy_custom_trigger(): void
    {
        AutoReply::query()
            ->where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', AutoReply::TRIGGER_WELCOME)
            ->delete();
        AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'legacy-start',
            'response' => 'Пользовательское приветствие',
            'enabled' => true,
        ]);
        $migration = require database_path('migrations/2026_07_11_120000_backfill_system_auto_replies.php');

        $migration->up();

        $this->assertDatabaseHas('auto_replies', [
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => AutoReply::TRIGGER_WELCOME,
        ]);
        $this->assertDatabaseHas('auto_replies', [
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'legacy-start',
            'response' => 'Пользовательское приветствие',
        ]);
    }

    public function test_legacy_noncanonical_system_type_is_normalized_to_regular(): void
    {
        $legacy = AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'start',
            'response' => 'Старое обычное правило',
            'enabled' => true,
        ]);
        AutoReplyTranslation::create([
            'auto_reply_id' => $legacy->id,
            'locale' => 'en',
            'text' => 'Legacy regular rule',
            'status' => AutoReplyTranslation::STATUS_READY,
            'source' => AutoReplyTranslation::SOURCE_MANUAL,
        ]);
        $migration = require database_path('migrations/2026_07_14_072000_normalize_legacy_system_auto_reply_types.php');

        $migration->up();

        $this->assertDatabaseHas('auto_replies', [
            'id' => $legacy->id,
            'type' => AutoReply::TYPE_REGULAR,
            'trigger' => 'start',
            'response' => 'Старое обычное правило',
        ]);
        $this->assertDatabaseHas('auto_reply_translations', [
            'auto_reply_id' => $legacy->id,
            'locale' => 'en',
            'text' => 'Legacy regular rule',
        ]);
    }

    public function test_system_backfill_does_not_treat_cross_type_trigger_as_target_record(): void
    {
        AutoReply::query()
            ->where('type', AutoReply::TYPE_BAN)
            ->where('trigger', AutoReply::TRIGGER_BAN)
            ->delete();
        AutoReply::create([
            'type' => AutoReply::TYPE_REGULAR,
            'trigger' => AutoReply::TRIGGER_BAN,
            'response' => 'Пользовательская запись',
            'enabled' => true,
        ]);
        $migration = require database_path('migrations/2026_07_11_120000_backfill_system_auto_replies.php');

        $migration->up();

        $this->assertDatabaseHas('auto_replies', [
            'type' => AutoReply::TYPE_BAN,
            'trigger' => AutoReply::TRIGGER_BAN,
        ]);
    }

    private function user(string $locale): BotUser
    {
        return BotUser::create([
            'chat_id' => random_int(100000, 999999),
            'platform' => 'vk',
            'display_name' => 'Иван',
            'preferred_language_code' => $locale,
        ]);
    }

    private function reply(string $type): AutoReply
    {
        return AutoReply::query()
            ->where('type', $type)
            ->where('trigger', AutoReply::systemTriggers()[$type])
            ->firstOrFail();
    }

    private function translation(AutoReply $reply, string $locale, string $text): void
    {
        AutoReplyTranslation::create([
            'auto_reply_id' => $reply->id,
            'locale' => $locale,
            'text' => $text,
            'status' => AutoReplyTranslation::STATUS_READY,
            'source' => AutoReplyTranslation::SOURCE_MANUAL,
            'source_hash' => AutoReply::sourceHash($reply->response),
        ]);
    }
}
