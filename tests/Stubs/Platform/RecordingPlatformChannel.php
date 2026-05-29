<?php

namespace Tests\Stubs\Platform;

use App\Contracts\PlatformChannel;
use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;

/**
 * Test double for {@see PlatformChannel} that records the calls it receives,
 * standing in for an external pluggable platform module (e.g. the paid Avito
 * package) in core tests.
 */
class RecordingPlatformChannel implements PlatformChannel
{
    /**
     * Recorded deliverAiAnswer() invocations.
     *
     * @var array<int, array{botUser: BotUser, text: string, updateDto: TelegramUpdateDto|null}>
     */
    public array $aiAnswers = [];

    /**
     * Recorded sendFeedbackForm() invocations.
     *
     * @var array<int, array{botUser: BotUser, feedbackId: int}>
     */
    public array $feedbackForms = [];

    public function __construct(private string $platform = 'avito')
    {
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function deliverAiAnswer(BotUser $botUser, string $text, ?TelegramUpdateDto $updateDto = null): void
    {
        $this->aiAnswers[] = [
            'botUser' => $botUser,
            'text' => $text,
            'updateDto' => $updateDto,
        ];
    }

    public function sendFeedbackForm(BotUser $botUser, int $feedbackId): void
    {
        $this->feedbackForms[] = [
            'botUser' => $botUser,
            'feedbackId' => $feedbackId,
        ];
    }
}
