<?php

namespace App\Modules\Feedback\Actions;

use App\Models\Feedback;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
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
    public function execute(string $callbackData, ?int $messageId = null, ?int $chatId = null): void
    {
        $parsed = $this->parseCallbackData($callbackData);
        if ($parsed === null) {
            Log::channel('app')->warning('HandleFeedbackRating: invalid callback_data', [
                'source' => 'feedback_rating_invalid',
                'callback_data' => $callbackData,
            ]);
            return;
        }

        ['feedbackId' => $feedbackId, 'score' => $score] = $parsed;

        $feedback = Feedback::find($feedbackId);
        if ($feedback === null) {
            Log::channel('app')->warning('HandleFeedbackRating: feedback record not found', [
                'source' => 'feedback_rating_not_found',
                'feedback_id' => $feedbackId,
            ]);
            return;
        }

        $feedback->update([
            'rating' => $score,
            'status' => 'completed_no_comment',
        ]);

        Log::channel('app')->info('HandleFeedbackRating: rating saved', [
            'source' => 'feedback_rating_saved',
            'feedback_id' => $feedbackId,
            'rating' => $score,
            'bot_user_id' => $feedback->bot_user_id,
        ]);

        // Edit the original feedback form message to a thank-you text if Telegram message context is available
        if ($messageId !== null && $chatId !== null) {
            // TODO: move text to lang/ru/messages.php when i18n infrastructure is added
            $thankYouText = 'Спасибо за отзыв! Ваша оценка принята.';

            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'editMessageText',
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $thankYouText,
                'parse_mode' => 'html',
                'reply_markup' => ['inline_keyboard' => []],
            ]));
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
