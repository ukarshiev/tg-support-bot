<?php

namespace App\Modules\Telegram\Support;

use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Log;

final class TelegramPipelineTrace
{
    public static function id(TelegramUpdateDto $update): string
    {
        if (!empty($update->callbackId)) {
            return 'telegram:callback:' . $update->callbackId;
        }

        if ($update->updateId > 0) {
            return 'telegram:update:' . $update->updateId;
        }

        return 'telegram:message:' . ($update->messageId ?? 'unknown');
    }

    public static function log(string $event, string $traceId, array $context = []): void
    {
        Log::channel('app')->info('Telegram pipeline', array_merge([
            'source' => 'telegram_pipeline',
            'pipeline_event' => $event,
            'trace_id' => $traceId,
            'at_unix_ms' => (int) floor(microtime(true) * 1000),
        ], $context));
    }
}
