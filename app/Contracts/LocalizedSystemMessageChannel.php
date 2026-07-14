<?php

namespace App\Contracts;

use App\Models\BotUser;

/**
 * Optional capability for private platform modules that support localized
 * system messages. Existing PlatformChannel implementations remain valid.
 */
interface LocalizedSystemMessageChannel
{
    /**
     * Deliver a localized plain-text system message to the client.
     */
    public function sendSystemMessage(BotUser $botUser, string $type, string $text): void;

    /**
     * Deliver the localized feedback prompt with rating controls.
     */
    public function sendLocalizedFeedbackForm(BotUser $botUser, int $feedbackId, string $text): void;
}
