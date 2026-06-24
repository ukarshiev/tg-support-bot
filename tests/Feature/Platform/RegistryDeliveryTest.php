<?php

namespace Tests\Feature\Platform;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Models\Message;
use App\Modules\Ai\Actions\DeliverAiAnswerToUser;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use App\Modules\Max\Jobs\SendMaxSimpleMessageJob;
use App\Platform\PlatformChannelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Stubs\Platform\RecordingPlatformChannel;
use Tests\TestCase;

/**
 * Verifies the core extension point: cross-platform delivery delegates to a
 * PlatformChannel registered in PlatformChannelRegistry for platforms the core
 * does not handle natively, while built-in telegram/vk/max are untouched.
 *
 * Uses a fictitious 'demo_platform' key (not a real platform such as 'avito')
 * so these tests assert the registry mechanism itself and stay green whether or
 * not an optional platform package (e.g. the paid Avito module) is installed.
 */
class RegistryDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BotUser::truncate();
        Message::truncate();
        Feedback::truncate();
        Queue::fake();
    }

    public function test_deliver_ai_answer_delegates_to_registered_channel_for_pluggable_platform(): void
    {
        $channel = new RecordingPlatformChannel('demo_platform');
        app(PlatformChannelRegistry::class)->register($channel);

        $botUser = BotUser::create(['chat_id' => 50001, 'platform' => 'demo_platform']);

        $result = (new DeliverAiAnswerToUser())->execute($botUser, 'Hello from Avito');

        $this->assertTrue($result);
        $this->assertCount(1, $channel->aiAnswers);
        $this->assertEquals($botUser->id, $channel->aiAnswers[0]['botUser']->id);
        $this->assertEquals('Hello from Avito', $channel->aiAnswers[0]['text']);

        Queue::assertNothingPushed();
    }

    public function test_send_feedback_form_delegates_to_registered_channel_for_pluggable_platform(): void
    {
        $channel = new RecordingPlatformChannel('demo_platform');
        app(PlatformChannelRegistry::class)->register($channel);

        $botUser = BotUser::create(['chat_id' => 50002, 'platform' => 'demo_platform']);

        (new SendFeedbackForm())->execute($botUser);

        $feedback = Feedback::where('bot_user_id', $botUser->id)->first();

        $this->assertCount(1, $channel->feedbackForms);
        $this->assertEquals($botUser->id, $channel->feedbackForms[0]['botUser']->id);
        $this->assertEquals($feedback->id, $channel->feedbackForms[0]['feedbackId']);

        // Feedback record is still created regardless of the delivery channel.
        $this->assertDatabaseHas('feedbacks', [
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_builtin_platform_bypasses_registry(): void
    {
        // A channel registered under a built-in key must NOT intercept it:
        // the core handles 'max' directly and never consults the registry.
        $channel = new RecordingPlatformChannel('max');
        app(PlatformChannelRegistry::class)->register($channel);

        $botUser = BotUser::create(['chat_id' => 50003, 'platform' => 'max']);

        $result = (new DeliverAiAnswerToUser())->execute($botUser, 'hi');

        $this->assertTrue($result);
        $this->assertCount(0, $channel->aiAnswers);

        Queue::assertPushed(SendMaxSimpleMessageJob::class);
    }

    public function test_returns_false_when_no_channel_registered_for_unknown_platform(): void
    {
        $botUser = BotUser::create(['chat_id' => 50004, 'platform' => 'demo_platform']);

        $result = (new DeliverAiAnswerToUser())->execute($botUser, 'hi');

        $this->assertFalse($result);
        Queue::assertNothingPushed();
    }
}
