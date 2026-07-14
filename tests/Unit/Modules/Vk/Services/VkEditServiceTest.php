<?php

namespace Tests\Unit\Modules\Vk\Services;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Jobs\SendVkTelegramMessageJob;
use App\Modules\Vk\Jobs\MirrorVkIncomingMessageJob;
use App\Modules\Vk\Services\VkEditService;
use App\Modules\Vk\Services\VkMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Vk\VkUpdateDtoMock;
use Tests\TestCase;

class VkEditServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $chatId;

    private ?BotUser $botUser;

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Message::truncate();

        $this->chatId = time();
        $this->botUser = BotUser::getUserByChatId($this->chatId, 'vk');
    }

    public function test_edit_text_message(): void
    {
        // новое сообщение
        $dtoNewMessage = VkUpdateDtoMock::getDto();
        (new VkMessageService($dtoNewMessage))->handleUpdate();
        // ---------------

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[MirrorVkIncomingMessageJob::class] ?? [];
        $this->assertNotEmpty($pushed);

        $messageData = Message::where([
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'incoming',
            'platform' => 'vk',
            'from_id' => $dtoNewMessage->id,
        ])->sole();
        $messageData->update(['to_id' => rand(1, 100000)]);

        // изменение сообщения
        $dtoParams = VkUpdateDtoMock::getDtoParams();

        $dtoParams['type'] = 'message_edit';
        $dtoParams['object']['message']['id'] = $messageData->from_id;
        $dtoParams['object']['message']['text'] = 'Test text, new!';

        $dtoUpdateMessage = VkUpdateDtoMock::getDto($dtoParams);

        (new VkEditService($dtoUpdateMessage))->handleUpdate();

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendVkTelegramMessageJob::class] ?? [];
        $this->assertNotEmpty($pushed);

        // Проверка редактирования джобы
        $jobData = $pushed[0]['job'];

        $this->assertEquals($dtoUpdateMessage->text, $jobData->queryParams->text);
        $this->assertEquals($this->botUser->topic_id, $jobData->queryParams->message_thread_id);
        $this->assertEquals('-100000000000', $jobData->queryParams->chat_id);
        $this->assertEquals($dtoUpdateMessage, $jobData->updateDto);
    }
}
