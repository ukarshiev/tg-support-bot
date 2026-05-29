<?php

namespace App\Contracts;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;

/**
 * Contract for a pluggable platform channel.
 *
 * Implemented by external/pluggable platform modules (e.g. the paid Avito
 * module shipped as a separate private package) and registered in
 * {@see \App\Platform\PlatformChannelRegistry} from the module's ServiceProvider.
 *
 * The core needs no edits to support a new platform: cross-platform delivery
 * (AI answers, feedback form) resolves the channel from the registry by the
 * string key {@see BotUser::$platform}. The built-in telegram/vk/max platforms
 * are handled by the core directly and do not go through this contract.
 */
interface PlatformChannel
{
    /**
     * Platform key matching the BotUser.platform value (e.g. 'avito').
     * The channel is registered and resolved in the registry by this key.
     *
     * @return string
     */
    public function platform(): string;

    /**
     * Deliver an AI-generated answer to the user (auto-reply / Accept).
     * Called by the core {@see \App\Modules\Ai\Actions\DeliverAiAnswerToUser}
     * for platforms the core does not handle natively.
     *
     * @param BotUser                $botUser   Recipient
     * @param string                 $text      Answer text (carries Telegram HTML;
     *                                          strip it in the module if needed)
     * @param TelegramUpdateDto|null $updateDto Originating update, if any
     *
     * @return void
     */
    public function deliverAiAnswer(BotUser $botUser, string $text, ?TelegramUpdateDto $updateDto = null): void;

    /**
     * Send the post-close support-rating form to the user.
     * Called by the core {@see \App\Modules\Feedback\Actions\SendFeedbackForm}.
     *
     * The rating callback must use the format
     * feedback_rate_{botUserId}_{feedbackId}_{score} so it is recognized by
     * {@see \App\Modules\Feedback\Actions\HandleFeedbackRating}.
     *
     * @param BotUser $botUser    Recipient
     * @param int     $feedbackId Feedback record id (status='awaiting_rating')
     *
     * @return void
     */
    public function sendFeedbackForm(BotUser $botUser, int $feedbackId): void;
}
