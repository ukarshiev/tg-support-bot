<?php

namespace App\Modules\Feedback\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Feedback\Jobs\DeliverFeedbackFormJob;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use Illuminate\Support\Facades\Log;

/** Создаёт ровно одну актуальную форму и передаёт подтверждаемую доставку отдельной очереди. */
class SendFeedbackForm
{
    public function execute(BotUser $botUser): void
    {
        $superseded = Feedback::query()
            ->where('bot_user_id', $botUser->id)
            ->whereIn('status', ['awaiting_rating', 'delivery_pending'])
            ->update(['status' => 'superseded']);

        if ($superseded > 0) {
            Log::channel('app')->info('Old feedback forms superseded', [
                'source' => 'feedback_forms_superseded',
                'bot_user_id' => $botUser->id,
                'count' => $superseded,
            ]);
        }

        $text = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_FEEDBACK_REQUEST, $botUser);
        if ($text === null) {
            Log::channel('app')->info('SendFeedbackForm: disabled prompt skipped', [
                'source' => 'feedback_form_disabled',
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'locale' => $botUser->preferred_language_code,
            ]);

            return;
        }

        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'delivery_pending',
            'closed_at' => now(),
        ]);

        DeliverFeedbackFormJob::dispatch($feedback->id, $text);

        Log::channel('app')->info('Feedback form queued', [
            'source' => 'feedback_form_delivery_queued',
            'bot_user_id' => $botUser->id,
            'feedback_id' => $feedback->id,
            'platform' => $botUser->platform,
            'locale' => $botUser->preferred_language_code,
        ]);
    }
}
