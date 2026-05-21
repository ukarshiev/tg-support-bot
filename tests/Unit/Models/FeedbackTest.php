<?php

namespace Tests\Unit\Models;

use App\Models\BotUser;
use App\Models\Feedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BotUser::truncate();
        Feedback::truncate();
    }

    public function test_belongs_to_bot_user(): void
    {
        $botUser = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $this->assertInstanceOf(BotUser::class, $feedback->botUser);
        $this->assertEquals($botUser->id, $feedback->botUser->id);
    }

    public function test_bot_user_has_many_feedbacks(): void
    {
        $botUser = BotUser::create(['chat_id' => 1002, 'platform' => 'telegram']);
        Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'awaiting_rating', 'closed_at' => now()]);
        Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'completed_no_comment', 'rating' => 4, 'closed_at' => now()]);

        $this->assertCount(2, $botUser->feedbacks);
    }

    public function test_rating_is_cast_to_integer(): void
    {
        $botUser = BotUser::create(['chat_id' => 1003, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'rating' => 3,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);

        $this->assertIsInt($feedback->rating);
        $this->assertEquals(3, $feedback->rating);
    }

    public function test_rating_is_nullable(): void
    {
        $botUser = BotUser::create(['chat_id' => 1004, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $this->assertNull($feedback->rating);
    }

    public function test_comment_is_nullable(): void
    {
        $botUser = BotUser::create(['chat_id' => 1005, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $this->assertNull($feedback->comment);
    }

    public function test_fillable_fields(): void
    {
        $feedback = new Feedback();
        $fillable = $feedback->getFillable();

        $this->assertContains('bot_user_id', $fillable);
        $this->assertContains('rating', $fillable);
        $this->assertContains('comment', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('closed_at', $fillable);
    }

    public function test_closed_at_is_cast_to_datetime(): void
    {
        $botUser = BotUser::create(['chat_id' => 1006, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $feedback->closed_at);
    }
}
