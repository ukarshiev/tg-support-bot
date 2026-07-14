<?php

namespace Tests\Unit\Services\AutoReplies;

use App\Models\AutoReplyVariable;
use App\Models\BotUser;
use App\Modules\Telegram\Actions\GetChat;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Services\AutoReplies\AutoReplyVariableRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoReplyVariableRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_global_and_all_client_variables(): void
    {
        AutoReplyVariable::create([
            'key' => 'connector',
            'name' => 'Connector',
            'value' => 'https://example.test',
            'enabled' => true,
        ]);

        $botUser = BotUser::create([
            'chat_id' => 123456,
            'platform' => 'telegram',
            'display_name' => 'Fallback Name',
            'username' => 'fallback_user',
        ]);

        $getChat = \Mockery::mock(GetChat::class);
        $getChat->shouldReceive('execute')->once()->with(123456)->andReturn(new TelegramAnswerDto(
            ok: true,
            rawData: ['result' => [
                'id' => 123456,
                'email' => 'client@example.test',
                'first_name' => 'Иван',
                'last_name' => 'Иванов',
                'username' => 'ivanov',
            ]],
        ));
        $this->app->instance(GetChat::class, $getChat);

        [$text, $warnings] = app(AutoReplyVariableRenderer::class)->render(
            '{{connector}} {id} {email} {first_name} {last_name} {username} {platform}',
            $botUser,
        );

        $this->assertSame(
            'https://example.test 123456 client@example.test Иван Иванов ivanov telegram',
            $text,
        );
        $this->assertSame([], $warnings);
    }

    public function test_keeps_client_variable_in_preview_without_selected_client(): void
    {
        [$text, $warnings] = app(AutoReplyVariableRenderer::class)->render('Привет, {first_name}!');

        $this->assertSame('Привет, {first_name}!', $text);
        $this->assertSame(['Переменная {first_name} требует выбранного клиента.'], $warnings);
    }
}
