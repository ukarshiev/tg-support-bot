<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Modules\Telegram\Actions\SendContactMessage;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendTelegramContactMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_card_waits_for_topic_instead_of_falling_back_to_general(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $botUser = BotUser::create([
            'chat_id' => 12345,
            'platform' => 'telegram',
            'topic_id' => null,
        ]);

        Http::fake();
        (new SendContactMessageJob($botUser->id))->handle(app(SendContactMessage::class));

        Http::assertNothingSent();
        Queue::assertPushed(TopicCreateJob::class);
    }

    public function test_contact_card_is_sent_only_to_assigned_topic(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');
        $botUser = BotUser::create([
            'chat_id' => 12346,
            'platform' => 'telegram',
            'topic_id' => 4321,
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 10, 'chat' => ['id' => -100123456789]],
        ])]);

        $job = new SendContactMessageJob($botUser->id);
        $job->handle(app(SendContactMessage::class));
        $job->handle(app(SendContactMessage::class));

        Http::assertSent(fn ($request): bool =>
            str_ends_with($request->url(), '/sendMessage')
            && (int) $request['message_thread_id'] === 4321
            && (string) $request['chat_id'] === '-100123456789');
        $sendMessageRequests = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendMessage'));
        $this->assertCount(1, $sendMessageRequests);
        $this->assertDatabaseHas('delivery_operations', [
            'operation_key' => $job->operationKey,
            'status' => DeliveryOperation::STATUS_DELIVERED,
        ]);
    }
}
