<?php

namespace App\Modules\Telegram\Services\Tg;

use App\Models\Message;
use App\Modules\Telegram\Actions\ConversionMessageText;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\ActionService\Edit\FromTgEditService;
use Illuminate\Support\Facades\Log;

class TgEditMessageService extends FromTgEditService
{
    public function __construct(TelegramUpdateDto $update)
    {
        parent::__construct($update);
    }

    public function handleUpdate(): void
    {
        if ($this->update->typeQuery !== 'edited_message') {
            Log::channel('app')->warning('Telegram edit skipped: unknown event type', [
                'type_query' => $this->update->typeQuery,
                'update_id' => $this->update->updateId,
            ]);
            return;
        }

        $messageData = Message::where([
            'message_type' => $this->typeMessage,
            'from_id' => $this->update->messageId,
        ])->first();

        $toIdMessage = $messageData?->to_id;
        if (empty($toIdMessage)) {
            Log::channel('app')->warning('Telegram edit skipped: original message not found', [
                'update_id' => $this->update->updateId,
                'message_id' => $this->update->messageId,
                'message_type' => $this->typeMessage,
            ]);
            return;
        }

        $this->messageParamsDTO->message_id = $toIdMessage;

        if (!empty($this->update->rawData['edited_message']['photo']) ||
            !empty($this->update->rawData['edited_message']['document'])) {
            $this->editMessageCaption();
        } else {
            $this->editMessageText();
        }

        SendTelegramMessageJob::dispatch(
            $this->botUser->id,
            $this->update,
            $this->messageParamsDTO,
            $this->typeMessage,
        );
    }

    /**
     * Edit message
     */
    protected function editMessageText(): void
    {
        $this->messageParamsDTO->methodQuery = 'editMessageText';

        $this->messageParamsDTO->text = $this->update->text;
        if (!empty($this->update->entities) && ConversionMessageText::hasFormattingEntities($this->update->entities)) {
            $this->messageParamsDTO->text = ConversionMessageText::conversionMarkdownFormat($this->update->text, $this->update->entities);
            $this->messageParamsDTO->parse_mode = 'MarkdownV2';
        }
    }

    /**
     * Edit message with photo or document
     */
    protected function editMessageCaption(): void
    {
        $this->messageParamsDTO->methodQuery = 'editMessageCaption';

        $this->messageParamsDTO->caption = $this->update->caption;
        if (!empty($this->update->entities) && ConversionMessageText::hasFormattingEntities($this->update->entities)) {
            $this->messageParamsDTO->caption = ConversionMessageText::conversionMarkdownFormat($this->update->caption, $this->update->entities);
            $this->messageParamsDTO->parse_mode = 'MarkdownV2';
        }
    }
}
