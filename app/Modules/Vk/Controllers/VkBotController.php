<?php

namespace App\Modules\Vk\Controllers;

use App\Models\BotUser;
use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Vk\Actions\SendBannedMessageVk;
use App\Modules\Vk\DTOs\VkUpdateDto;
use App\Modules\Vk\Services\VkEditService;
use App\Modules\Vk\Services\VkMessageService;
use App\Services\LanguageSelectionService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class VkBotController
{
    /**
     * @return Response
     *
     * @throws \Exception
     */
    public function bot_query(Request $request): Response
    {
        if ($request->type === 'confirmation') {
            return response((string) app(SettingsService::class)->get('vk.confirm_code'), 200);
        }

        $dataHook = VkUpdateDto::fromRequest($request);
        if (empty($dataHook)) {
            return response('ok', 200);
        }

        $cacheKey = 'vk_event_' . hash('sha256', $dataHook->event_id);
        if (Cache::has($cacheKey)) {
            return response('ok', 200);
        }

        $lock = Cache::lock($cacheKey . ':lock', 30);
        if (!$lock->get()) {
            return response('retry', 503);
        }

        try {
            $botUser = (new BotUser())->getUserByChatId($dataHook->from_id, 'vk');

            if ($botUser->isBanned()) {
                app(SendBannedMessageVk::class)->execute($botUser);
                Cache::put($cacheKey, true, now()->addDay());

                return response('ok', 200);
            }

            switch ($dataHook->type) {
                case 'message_new':
                    if (app(LanguageSelectionService::class)->isMenuCommand($dataHook->text)) {
                        app(LanguageSelectionService::class)->sendSelector($botUser);
                        break;
                    }
                    (new VkMessageService($dataHook))->handleUpdate();
                    if (empty($botUser->preferred_language_code)) {
                        app(LanguageSelectionService::class)->sendSelector($botUser);
                    }
                    break;

                case 'message_edit':
                    (new VkEditService($dataHook))->handleUpdate();
                    break;

                case 'message_event':
                    $payload = $dataHook->rawData['object']['payload'] ?? [];
                    if (app(LanguageSelectionService::class)->handleCallback($botUser, $payload)) {
                        break;
                    }
                    $command = app(LanguageSelectionService::class)->callbackData($payload);
                    if ($command !== null && str_starts_with((string) $command, 'feedback_rate_')) {
                        app(HandleFeedbackRating::class)->execute(callbackData: (string) $command, actor: $botUser);
                    }
                    break;
            }

            Cache::put($cacheKey, true, now()->addDay());

            return response('ok', 200);
        } finally {
            $lock->release();
        }
    }
}
