<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EnrichBotUserProfileJob;
use App\Models\BotUser;
use App\Modules\Api\Services\FileService;
use App\Modules\Telegram\Actions\GetChat;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnrichBotUserProfileJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeBotUser(string $platform = 'telegram', ?Carbon $profileSyncedAt = null): BotUser
    {
        return BotUser::create([
            'chat_id' => 1001,
            'platform' => $platform,
            'profile_synced_at' => $profileSyncedAt,
        ]);
    }

    // ── TTL guard ──────────────────────────────────────────────────────────────

    public function test_ttl_guard_skips_fresh_profile(): void
    {
        Http::fake();
        Storage::fake('local');

        $botUser = $this->makeBotUser('telegram', now()->subDays(5));

        (new EnrichBotUserProfileJob($botUser))->handle();

        // No HTTP calls should have been made.
        Http::assertNothingSent();
        $this->assertNull($botUser->avatar_path);
    }

    // ── Telegram avatar fetched and stored ─────────────────────────────────────

    public function test_telegram_avatar_fetched_and_stored(): void
    {
        Storage::fake('local');

        $botUser = $this->makeBotUser('telegram');

        // Mock GetChat to return a response with a photo.
        $getChatAnswer = new TelegramAnswerDto(
            ok: true,
            rawData: [
                'ok' => true,
                'result' => [
                    'photo' => [
                        'small_file_id' => 'test_file_id_123',
                    ],
                ],
            ]
        );
        $this->instance(GetChat::class, new class ($getChatAnswer) extends GetChat {
            public function __construct(private TelegramAnswerDto $answer)
            {
            }

            public function execute(int $chatId): TelegramAnswerDto
            {
                return $this->answer;
            }
        });

        // Mock FileService::getTelegramFile to return a file_path.
        $this->instance(FileService::class, new class () extends FileService {
            public function __construct()
            {
            }

            public function getTelegramFile(string $fileId): array
            {
                return ['result' => ['file_path' => 'photos/avatar.jpg']];
            }
        });

        Http::fake([
            'https://api.telegram.org/file/bot*/photos/avatar.jpg' => Http::response('AVATAR_BYTES', 200),
        ]);

        (new EnrichBotUserProfileJob($botUser))->handle();

        $botUser->refresh();
        $this->assertSame("avatars/bot-user-{$botUser->id}.jpg", $botUser->avatar_path);
        $this->assertNotNull($botUser->profile_synced_at);
        Storage::disk('local')->assertExists("avatars/bot-user-{$botUser->id}.jpg");
    }

    // ── No photo field — still marks synced ────────────────────────────────────

    /**
     * Avatar connection failures must be logged without the bot credential.
     */
    public function test_avatar_download_failure_does_not_log_bot_token(): void
    {
        $token = '123456789:TEST_fake-token_for_unit_tests_0000000';
        app(\App\Services\Settings\SettingsService::class)->set('telegram.token', $token);
        $botUser = $this->makeBotUser('telegram');
        $answer = new TelegramAnswerDto(
            ok: true,
            rawData: ['ok' => true, 'result' => ['photo' => ['small_file_id' => 'avatar_file']]],
        );

        $this->instance(GetChat::class, new class ($answer) extends GetChat {
            /**
             * Supply the fake Telegram response.
             */
            public function __construct(private TelegramAnswerDto $answer)
            {
            }

            /**
             * Return a profile with an avatar.
             */
            public function execute(int $chatId): TelegramAnswerDto
            {
                return $this->answer;
            }
        });
        $this->instance(FileService::class, new class () extends FileService {
            /**
             * Avoid constructing external dependencies.
             */
            public function __construct()
            {
            }

            /**
             * Return fake avatar metadata.
             *
             * @return array<string, array<string, string>>
             */
            public function getTelegramFile(string $fileId): array
            {
                return ['result' => ['file_path' => 'photos/avatar.jpg']];
            }
        });
        Http::fake([
            'https://api.telegram.org/file/bot*' => function () use ($token): never {
                throw new ConnectionException(
                    'cURL error 28: SSL connection timeout for https://api.telegram.org/file/bot' . $token . '/photos/avatar.jpg',
                );
            },
        ]);

        Log::shouldReceive('channel')->once()->with('app')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use ($token): bool {
            $this->assertSame('EnrichBotUserProfileJob failed', $message);
            $this->assertStringNotContainsString($token, $context['error']);
            $this->assertStringNotContainsString(explode(':', $token, 2)[1], $context['error']);
            $this->assertStringContainsString('/file/bot[hidden]/photos/avatar.jpg', $context['error']);

            return true;
        });

        (new EnrichBotUserProfileJob($botUser))->handle();
    }

    public function test_no_photo_fallback(): void
    {
        Storage::fake('local');

        $botUser = $this->makeBotUser('telegram');

        $getChatAnswer = new TelegramAnswerDto(
            ok: true,
            rawData: [
                'ok' => true,
                'result' => [],   // no 'photo' key
            ]
        );
        $this->instance(GetChat::class, new class ($getChatAnswer) extends GetChat {
            public function __construct(private TelegramAnswerDto $answer)
            {
            }

            public function execute(int $chatId): TelegramAnswerDto
            {
                return $this->answer;
            }
        });

        $this->instance(FileService::class, new class () extends FileService {
            public function __construct()
            {
            }
        });

        Http::fake();

        (new EnrichBotUserProfileJob($botUser))->handle();

        $botUser->refresh();
        $this->assertNull($botUser->avatar_path);
        $this->assertNotNull($botUser->profile_synced_at);
    }

    // ── VK enrichment ─────────────────────────────────────────────────────────

    public function test_vk_enrichment(): void
    {
        Storage::fake('local');

        $botUser = $this->makeBotUser('vk');

        // Fake VK users.get call + photo download.
        Http::fake([
            'https://api.vk.com/method/users.get' => Http::response([
                'response' => [
                    [
                        'id' => 1001,
                        'first_name' => 'Анна',
                        'last_name' => 'Смирнова',
                        'photo_200' => 'https://vk.com/photo.jpg',
                    ],
                ],
            ], 200),
            'https://vk.com/photo.jpg' => Http::response('VK_AVATAR_BYTES', 200),
        ]);

        (new EnrichBotUserProfileJob($botUser))->handle();

        $botUser->refresh();
        $this->assertSame('Анна Смирнова', $botUser->display_name);
        $this->assertSame("avatars/bot-user-{$botUser->id}.jpg", $botUser->avatar_path);
        $this->assertNotNull($botUser->profile_synced_at);
        Storage::disk('local')->assertExists("avatars/bot-user-{$botUser->id}.jpg");
    }
}
