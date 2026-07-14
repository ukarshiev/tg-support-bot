<?php

namespace Tests\Unit\Modules\Ai\Services;

use App\Models\BotUser;
use App\Modules\Ai\Services\ShouldAiReply;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ShouldAiReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
    }

    private function makeUpdate(
        ?string $text = 'привет',
        string $typeSource = 'private',
        string $typeQuery = 'message'
    ): TelegramUpdateDto {
        return new TelegramUpdateDto(
            updateId: 1,
            typeQuery: $typeQuery,
            aiTechMessage: false,
            typeSource: $typeSource,
            chatId: 100,
            text: $text,
        );
    }

    private function makeBotUser(bool $banned = false, bool $closed = false, ?string $languageCode = 'ru'): BotUser
    {
        $botUser = new BotUser();
        $botUser->forceFill([
            'id' => 1,
            'is_banned' => $banned,
            'is_closed' => $closed,
            'platform' => 'telegram',
            'preferred_language_code' => $languageCode,
        ]);

        return $botUser;
    }

    public function test_returns_true_on_happy_path(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            $this->makeBotUser(),
        );

        $this->assertTrue($result);
    }

    public function test_returns_false_when_ai_disabled(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', false);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_for_non_private_chat(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(typeSource: 'supergroup'),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_for_callback_query(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(typeQuery: 'callback_query'),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_for_slash_command(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(text: '/start'),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_for_empty_text(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(text: '   '),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_for_null_text(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(text: null),
            $this->makeBotUser(),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_when_bot_user_is_null(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            null,
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_when_telegram_language_not_selected(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            $this->makeBotUser(languageCode: null),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_when_bot_user_is_banned(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            $this->makeBotUser(banned: true),
        );

        $this->assertFalse($result);
    }

    public function test_returns_false_when_bot_user_is_closed(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForUserMessage(
            $this->makeUpdate(),
            $this->makeBotUser(closed: true),
        );

        $this->assertFalse($result);
    }

    public function test_bot_user_text_returns_true_when_ai_enabled(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);

        $result = (new ShouldAiReply())->shouldGenerateForBotUserText(
            $this->makeBotUser(),
            'hello',
        );

        $this->assertTrue($result);
    }

    public function test_bot_user_text_returns_false_when_ai_disabled(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', false);

        $result = (new ShouldAiReply())->shouldGenerateForBotUserText(
            $this->makeBotUser(),
            'hello',
        );

        $this->assertFalse($result);
    }

    public function test_external_channel_waits_for_language_selection_too(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('ai.enabled', true);
        $botUser = $this->makeBotUser(languageCode: null);
        $botUser->platform = 'vk';

        $this->assertFalse((new ShouldAiReply())->shouldGenerateForBotUserText($botUser, 'hello'));
    }

    public function test_financial_or_subscription_complaint_forces_draft_only(): void
    {
        $service = new ShouldAiReply();

        $this->assertTrue($service->shouldUseDraftOnly(
            $this->makeBotUser(),
            'it was down for most of my subscription',
        ));

        $this->assertTrue($service->shouldUseDraftOnly(
            $this->makeBotUser(),
            'верните деньги, доступ к платному каналу не работает',
        ));
    }

    public function test_regular_support_question_does_not_force_draft_only(): void
    {
        $service = new ShouldAiReply();

        $this->assertFalse($service->shouldUseDraftOnly(
            $this->makeBotUser(),
            'подскажи ссылку на группу RelaxaClub Gallery',
        ));
    }
}
