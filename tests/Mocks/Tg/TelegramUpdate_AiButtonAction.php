<?php

namespace Tests\Mocks\Tg;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class TelegramUpdate_AiButtonAction extends TelegramUpdateDto
{
    /**
     * @param BotUser|null $botUser
     *
     * @return array
     */
    public static function getDtoParams(?BotUser $botUser = null): array
    {
        $timestamp = time();
        $messageIdentifier = PayloadIdentifier::next();

        return [
            'update_id' => $messageIdentifier,
            'callback_query' => [
                'id' => $messageIdentifier,
                'from' => [
                    'id' => 9_000_000_001,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                    'language_code' => 'ru',
                ],
                'message' => [
                    'message_id' => $messageIdentifier,
                    'from' => [
                        'id' => 9_000_000_002,
                        'is_bot' => true,
                        'first_name' => 'Prog-Time AI',
                        'username' => 'prog_time_ai_bot',
                    ],
                    'chat' => [
                        'id' => -100_000_000_000,
                        'title' => 'Prog-Time | Чаты',
                        'is_forum' => true,
                        'type' => 'supergroup',
                    ],
                    'date' => $timestamp,
                    'edit_date' => $timestamp,
                    'message_thread_id' => 0,
                    'reply_to_message' => [
                        'message_id' => $messageIdentifier + 1,
                        'from' => [
                            'id' => 9_000_000_003,
                            'is_bot' => true,
                            'first_name' => 'Prog-Time |Администратор сайта',
                            'username' => 'prog_time_bot',
                        ],
                        'chat' => [
                            'id' => -100_000_000_000,
                            'title' => 'Prog-Time | Чаты',
                            'is_forum' => true,
                            'type' => 'supergroup',
                        ],
                        'date' => $timestamp,
                        'message_thread_id' => 0,
                        'forum_topic_created' => [
                            'name' => '#1424646511 (telegram)',
                            'icon_color' => 7322096,
                            'icon_custom_emoji_id' => '5417915203100613993',
                        ],
                        'is_topic_message' => true,
                    ],
                    'text' => "📄 Инструкция: \nнапиши приветственное сообщение  \n\n🤖 Ответ от AI: \nДобро пожаловать в TG Support Bot!",
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '✅ Отправить',
                                    'callback_data' => 'ai_message_send_' . $messageIdentifier,
                                ],
                                [
                                    'text' => '❌ Отменить',
                                    'callback_data' => 'ai_message_delete_' . $messageIdentifier,
                                ],
                            ],
                            [
                                [
                                    'text' => '📝 Редактировать ответ',
                                    'switch_inline_query_current_chat' => 'ai_message_edit_' . $messageIdentifier . " \n\nДобро пожаловать в TG Support Bot!",
                                ],
                            ],
                        ],
                    ],
                    'is_topic_message' => true,
                ],
                'chat_instance' => 8_000_000_001,
                'data' => 'ai_message_send_' . $messageIdentifier,
            ],
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return TelegramUpdateDto
     */
    public static function getDto(array $dtoParams = []): TelegramUpdateDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return TelegramUpdateDto::fromRequest($request);
    }
}
