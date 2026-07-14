<?php

namespace App\Modules\Max\Controllers;

use App\Models\BotUser;
use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Max\Actions\SendBannedMessageMax;
use App\Modules\Max\DTOs\MaxUpdateDto;
use App\Modules\Max\Services\MaxMessageService;
use App\Services\LanguageSelectionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class MaxBotController
{
    /**
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function bot_query(Request $request): Response
    {
        if ($request->input('update_type') === 'bot_started') {
            $userId = $request->input('user.user_id');
            if (!empty($userId)) {
                $botUser = (new BotUser())->getUserByChatId((int) $userId, 'max');
                if (!$botUser->isBanned()) {
                    app(LanguageSelectionService::class)->sendSelector($botUser);
                }
            }

            return response('ok', 200);
        }

        $dataHook = MaxUpdateDto::fromRequest($request);
        if (empty($dataHook)) {
            return response('ok', 200);
        }

        $cacheKey = 'max_event_' . hash('sha256', $dataHook->event_id);
        if (Cache::has($cacheKey)) {
            return response('ok', 200);
        }

        $lock = Cache::lock($cacheKey . ':lock', 30);
        if (!$lock->get()) {
            return response('retry', 503);
        }

        try {
            $botUser = (new BotUser())->getUserByChatId($dataHook->from_id, 'max');

            if ($botUser->isBanned()) {
                app(SendBannedMessageMax::class)->execute($botUser);
                Cache::put($cacheKey, true, now()->addDay());

                return response('ok', 200);
            }

            if ($dataHook->type === 'message_created') {
                if (app(LanguageSelectionService::class)->isMenuCommand($dataHook->text)) {
                    app(LanguageSelectionService::class)->sendSelector($botUser);

                    Cache::put($cacheKey, true, now()->addDay());

                    return response('ok', 200);
                }
                (new MaxMessageService($dataHook))->handleUpdate();
                if (empty($botUser->preferred_language_code)) {
                    app(LanguageSelectionService::class)->sendSelector($botUser);
                }
            } elseif ($dataHook->type === 'message_callback') {
                $payload = $dataHook->rawData['callback']['payload'] ?? null;
                if (app(LanguageSelectionService::class)->handleCallback($botUser, $payload)) {
                    Cache::put($cacheKey, true, now()->addDay());

                    return response('ok', 200);
                }
                if ($payload !== null && str_starts_with((string) $payload, 'feedback_rate_')) {
                    app(HandleFeedbackRating::class)->execute(callbackData: (string) $payload, actor: $botUser);
                }
            }

            Cache::put($cacheKey, true, now()->addDay());

            return response('ok', 200);
        } finally {
            $lock->release();
        }
    }
}
