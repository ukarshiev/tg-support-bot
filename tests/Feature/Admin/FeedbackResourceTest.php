<?php

namespace Tests\Feature\Admin;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Models\User;
use App\Modules\Admin\Filament\Resources\FeedbackResource;
use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ListFeedbacks;
use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ViewFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeedbackResourceTest extends TestCase
{
    use RefreshDatabase;

    protected BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->botUser = BotUser::create(['chat_id' => 999001, 'platform' => 'telegram']);
    }

    public function test_can_render_list_page(): void
    {
        Livewire::test(ListFeedbacks::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_feedback_records(): void
    {
        $feedback = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'rating' => 4,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);

        Livewire::test(ListFeedbacks::class)
            ->assertCanSeeTableRecords([$feedback]);
    }

    public function test_can_filter_list_by_status(): void
    {
        $awaiting = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);
        $completed = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'rating' => 5,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);

        Livewire::test(ListFeedbacks::class)
            ->filterTable('status', 'awaiting_rating')
            ->assertCanSeeTableRecords([$awaiting])
            ->assertCanNotSeeTableRecords([$completed]);
    }

    public function test_can_filter_list_by_rating(): void
    {
        $rating4 = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'rating' => 4,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);
        $rating2 = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'rating' => 2,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);

        Livewire::test(ListFeedbacks::class)
            ->filterTable('rating', '4')
            ->assertCanSeeTableRecords([$rating4])
            ->assertCanNotSeeTableRecords([$rating2]);
    }

    public function test_can_render_view_page(): void
    {
        $feedback = Feedback::create([
            'bot_user_id' => $this->botUser->id,
            'rating' => 3,
            'status' => 'completed_no_comment',
            'closed_at' => now(),
        ]);

        Livewire::test(ViewFeedback::class, ['record' => $feedback->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_resource_is_read_only_no_create(): void
    {
        $this->assertFalse(FeedbackResource::canCreate());
    }
}
