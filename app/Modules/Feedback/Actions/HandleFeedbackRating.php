<?php

namespace App\Modules\Feedback\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\Feedback;
use App\Models\Message;
use App\Modules\Feedback\Jobs\DeliverFeedbackThankYouJob;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Handle user's rating callback for a feedback form.
 *
 * Parses the callback_data / payload (format: feedback_rate_{botUserId}_{feedbackId}_{score}),
 * saves the rating on the Feedback record, sets status='completed_no_comment',
 * and edits the original message text to a thank-you string.
 *
 * Called from:
 * - TelegramBotController::checkBotQuery() for Telegram callback_query
 * - VkBotController::bot_query() for VK message_event
 * - MaxBotController::bot_query() for Max message_callback
 */
class HandleFeedbackRating
{
    /**
     * Process a rating callback.
     *
     * @param string   $callbackData Raw callback string (feedback_rate_{botUserId}_{feedbackId}_{score})
     * @param int|null $messageId    Message ID to edit (Telegram only; null for VK/Max)
     * @param int|null $chatId       Chat ID for Telegram editMessageText (user's private chat)
     *
     * @return void
     */
    public function execute(
        string $callbackData,
        ?int $messageId = null,
        ?int $chatId = null,
        ?BotUser $actor = null,
    ): void {
        $parsed = $this->parseCallbackData($callbackData);
        if ($parsed === null) {
            Log::channel('app')->warning('HandleFeedbackRating: invalid callback_data', [
                'source' => 'feedback_rating_invalid',
                'callback_data' => $callbackData,
            ]);
            return;
        }

        ['botUserId' => $botUserId, 'feedbackId' => $feedbackId, 'score' => $score] = $parsed;

        $feedback = Feedback::find($feedbackId);
        if ($feedback === null) {
            Log::channel('app')->warning('HandleFeedbackRating: feedback record not found', [
                'source' => 'feedback_rating_not_found',
                'feedback_id' => $feedbackId,
            ]);
            return;
        }

        if ((int) $feedback->bot_user_id !== $botUserId) {
            Log::channel('app')->warning('HandleFeedbackRating: callback user does not own feedback', [
                'source' => 'feedback_rating_owner_mismatch',
                'feedback_id' => $feedbackId,
                'callback_bot_user_id' => $botUserId,
                'feedback_bot_user_id' => $feedback->bot_user_id,
            ]);
            return;
        }

        if ($actor !== null && (int) $actor->id !== $botUserId) {
            Log::channel('app')->warning('HandleFeedbackRating: callback actor mismatch', [
                'source' => 'feedback_rating_actor_mismatch',
                'feedback_id' => $feedbackId,
                'callback_bot_user_id' => $botUserId,
                'actor_bot_user_id' => $actor->id,
            ]);

            return;
        }

        $updated = Feedback::query()
            ->whereKey($feedbackId)
            ->where('status', 'awaiting_rating')
            ->update([
            'rating' => $score,
            'status' => 'completed_no_comment',
        ]);

        if ($updated !== 1) {
            Log::channel('app')->info('HandleFeedbackRating: duplicate callback ignored', [
                'source' => 'feedback_rating_duplicate',
                'feedback_id' => $feedbackId,
                'bot_user_id' => $botUserId,
            ]);

            return;
        }

        $feedback->refresh();

        Log::channel('app')->info('HandleFeedbackRating: rating saved', [
            'source' => 'feedback_rating_saved',
            'feedback_id' => $feedbackId,
            'rating' => $score,
            'bot_user_id' => $feedback->bot_user_id,
        ]);

        // Surface the rating in the conversation (chat workspace history + group topic)
        $this->postRatingToChat($feedback, $score);

        /** @var BotUser|null $botUser */
        $botUser = $feedback->botUser;
        if ($botUser !== null) {
            $thankYouText = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_FEEDBACK_THANK_YOU, $botUser);

            Log::channel('app')->info('HandleFeedbackRating: localized acknowledgement resolved', [
                'source' => 'feedback_thank_you_text_resolved',
                'feedback_id' => $feedbackId,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'locale' => $botUser->preferred_language_code,
                'auto_reply_type' => AutoReply::TYPE_FEEDBACK_THANK_YOU,
            ]);

            if ($thankYouText !== null) {
                DeliverFeedbackThankYouJob::dispatch($feedback->id, $thankYouText, $messageId, $chatId);
            }
        }
    }

    /**
     * Record the user's rating as a chat message.
     *
     * Always writes an incoming `messages` row so the rating shows in the
     * conversation history (admin chat workspace). In telegram_group mode it
     * additionally posts the rating into the user's forum topic so managers
     * working in the supergroup see it as well.
     *
     * @param Feedback $feedback
     * @param int      $score
     *
     * @return void
     */
    private function postRatingToChat(Feedback $feedback, int $score): void
    {
        /** @var BotUser|null $botUser */
        $botUser = $feedback->botUser;
        if ($botUser === null) {
            return;
        }

        $text = 'Оценка обращения: ' . str_repeat('⭐', $score) . " ({$score}/5)";

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => $text,
        ]);

        // telegram_group mode: mirror the rating into the user's forum topic.
        if ($botUser->platform === 'telegram' && !empty($botUser->topic_id)) {
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

            if ($groupId !== '') {
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $groupId,
                    'message_thread_id' => $botUser->topic_id,
                    'text' => $text,
                    'parse_mode' => 'html',
                ]));
            }
        }
    }

    /**
     * Parse callback_data string.
     *
     * Expected format: feedback_rate_{botUserId}_{feedbackId}_{score}
     *
     * @param string $callbackData
     *
     * @return array{botUserId: int, feedbackId: int, score: int}|null
     */
    public function parseCallbackData(string $callbackData): ?array
    {
        if (!preg_match('/^feedback_rate_(\d+)_(\d+)_([1-5])$/', $callbackData, $matches)) {
            return null;
        }

        return [
            'botUserId' => (int) $matches[1],
            'feedbackId' => (int) $matches[2],
            'score' => (int) $matches[3],
        ];
    }
}
