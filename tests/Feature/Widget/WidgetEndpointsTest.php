<?php

namespace Tests\Feature\Widget;

use App\Models\BotUser;
use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use App\Models\ExternalUser;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the JS widget gateway endpoints.
 *
 * Routes:
 *  POST   /api/widget/{external_id}/messages  — sendMessage
 *  POST   /api/widget/{external_id}/files     — sendFile
 *  GET    /api/widget/{external_id}/messages  — getMessages
 *
 * All routes are authenticated by a short-lived X-Widget-Token.
 */
class WidgetEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private ExternalSource $source;

    private string $externalId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->externalId = 'session-abc-123';

        $this->source = ExternalSource::factory()->create([
            'name' => 'widget_test',
            'allowed_ips' => null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create an ExternalUser + BotUser pair for the current source/external_id.
     */
    private function seedSession(): BotUser
    {
        $externalUser = ExternalUser::firstOrCreate([
            'external_id' => $this->externalId,
            'source' => $this->source->name,
        ]);

        return BotUser::firstOrCreate([
            'chat_id' => $externalUser->id,
            'platform' => $this->source->name,
        ]);
    }

    /**
     * Create a Message + ExternalMessage row for the current session.
     */
    private function seedMessage(BotUser $botUser, string $text, string $type = 'incoming'): Message
    {
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => $this->source->name,
            'message_type' => $type,
            'from_id' => 0,
            'to_id' => 0,
        ]);

        $message->externalMessage()->create(['text' => $text]);

        return $message;
    }

    private function widgetSessionToken(?string $externalId = null, string $origin = 'https://client.example'): string
    {
        return Crypt::encryptString(json_encode([
            'source_id' => $this->source->id,
            'external_id' => $externalId ?? $this->externalId,
            'origin' => $origin,
            'expires_at' => now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function widgetHeaders(): array
    {
        return [
            'Origin' => 'https://client.example',
            'X-Widget-Token' => $this->widgetSessionToken(),
        ];
    }

    public function test_signed_session_is_bound_to_external_id_and_origin(): void
    {
        $token = $this->widgetSessionToken();

        $this->getJson("/api/widget/{$this->externalId}/messages", [
            'Origin' => 'https://client.example',
            'X-Widget-Token' => $token,
        ])->assertOk();

        $this->getJson('/api/widget/another-session/messages', [
            'Origin' => 'https://client.example',
            'X-Widget-Token' => $token,
        ])->assertUnauthorized();

        $this->getJson("/api/widget/{$this->externalId}/messages", [
            'Origin' => 'https://evil.example',
            'X-Widget-Token' => $token,
        ])->assertUnauthorized();
    }

    public function test_global_cors_does_not_allow_legacy_widget_key(): void
    {
        $response = $this->call(
            'OPTIONS',
            "/api/widget/{$this->externalId}/messages",
            server: [
                'HTTP_ORIGIN' => 'https://client.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-Widget-Key',
            ],
        );

        $this->assertStringNotContainsString(
            'X-Widget-Key',
            (string) $response->headers->get('Access-Control-Allow-Headers'),
        );
    }

    public function test_trusted_external_client_can_issue_widget_session(): void
    {
        $accessToken = str_repeat('t', 64);
        ExternalSourceAccessTokens::create([
            'external_source_id' => $this->source->id,
            'token' => $accessToken,
            'active' => true,
        ]);

        $response = $this->postJson(
            "/api/external/{$this->externalId}/widget-session",
            ['origin' => 'https://client.example'],
            ['Authorization' => 'Bearer ' . $accessToken],
        );

        $response->assertOk()->assertJsonStructure(['token', 'expires_at']);
        $this->getJson("/api/widget/{$this->externalId}/messages", [
            'Origin' => 'https://client.example',
            'X-Widget-Token' => $response->json('token'),
        ])->assertOk();
    }

    // ── POST /api/widget/{external_id}/messages ───────────────────────────────

    public function test_send_message_creates_incoming_message(): void
    {
        $response = $this->postJson(
            "/api/widget/{$this->externalId}/messages",
            ['text' => 'Hello support'],
            $this->widgetHeaders()
        );

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_send_message_requires_text(): void
    {
        $response = $this->postJson(
            "/api/widget/{$this->externalId}/messages",
            [],
            $this->widgetHeaders()
        );

        $response->assertUnprocessable();
    }

    public function test_send_message_rejects_text_over_4000_chars(): void
    {
        $response = $this->postJson(
            "/api/widget/{$this->externalId}/messages",
            ['text' => str_repeat('x', 4001)],
            $this->widgetHeaders()
        );

        $response->assertUnprocessable();
    }

    // ── POST /api/widget/{external_id}/files ──────────────────────────────────

    public function test_send_file_accepts_valid_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/widget/{$this->externalId}/files",
            ['uploaded_file' => $file],
            $this->widgetHeaders()
        );

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_send_file_requires_uploaded_file_field(): void
    {
        $response = $this->postJson(
            "/api/widget/{$this->externalId}/files",
            [],
            $this->widgetHeaders()
        );

        $response->assertUnprocessable();
    }

    // ── GET /api/widget/{external_id}/messages ────────────────────────────────

    public function test_get_messages_returns_empty_for_new_session(): void
    {
        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages",
            $this->widgetHeaders()
        );

        $response->assertOk()
            ->assertJson(['messages' => []]);
    }

    public function test_get_messages_returns_existing_messages(): void
    {
        $botUser = $this->seedSession();
        $this->seedMessage($botUser, 'First message');
        $this->seedMessage($botUser, 'Second message', 'outgoing');

        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages",
            $this->widgetHeaders()
        );

        $response->assertOk();
        $this->assertCount(2, $response->json('messages'));
    }

    public function test_get_messages_preserves_attachments_and_adds_signed_attachment_urls(): void
    {
        $botUser = $this->seedSession();
        $message = $this->seedMessage($botUser, 'File');
        $message->attachments()->create([
            'file_id' => 'telegram-file-id',
            'file_type' => 'document',
        ]);

        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages",
            $this->widgetHeaders(),
        )->assertOk();

        $response->assertJsonPath('messages.0.attachments.0', 'telegram-file-id');
        $url = (string) $response->json('messages.0.attachment_urls.0');
        $this->assertStringContainsString('/api/files/telegram-file-id?', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_get_messages_with_after_param_returns_only_newer(): void
    {
        $botUser = $this->seedSession();

        $msg1 = $this->seedMessage($botUser, 'One');
        $msg2 = $this->seedMessage($botUser, 'Two');
        $msg3 = $this->seedMessage($botUser, 'Three');
        $msg4 = $this->seedMessage($botUser, 'Four');
        $msg5 = $this->seedMessage($botUser, 'Five');

        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages?after={$msg3->id}",
            $this->widgetHeaders()
        );

        $response->assertOk();

        $ids = collect($response->json('messages'))->pluck('id')->all();

        $this->assertContains($msg4->id, $ids);
        $this->assertContains($msg5->id, $ids);
        $this->assertNotContains($msg1->id, $ids);
        $this->assertNotContains($msg2->id, $ids);
        $this->assertNotContains($msg3->id, $ids);
    }

    public function test_get_messages_with_no_after_returns_full_history(): void
    {
        $botUser = $this->seedSession();

        $this->seedMessage($botUser, 'Alpha');
        $this->seedMessage($botUser, 'Beta');
        $this->seedMessage($botUser, 'Gamma');

        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages",
            $this->widgetHeaders()
        );

        $response->assertOk();
        $this->assertCount(3, $response->json('messages'));
    }

    public function test_messages_include_direction_field(): void
    {
        $botUser = $this->seedSession();
        $this->seedMessage($botUser, 'Incoming msg', 'incoming');
        $this->seedMessage($botUser, 'Outgoing msg', 'outgoing');

        $response = $this->getJson(
            "/api/widget/{$this->externalId}/messages",
            $this->widgetHeaders()
        );

        $response->assertOk();

        $directions = collect($response->json('messages'))->pluck('direction')->sort()->values()->all();
        $this->assertSame(['in', 'out'], $directions);
    }

    // ── Auth guard — all three routes require X-Widget-Token ──────────────────

    public function test_send_message_returns_401_without_key(): void
    {
        $this->postJson(
            "/api/widget/{$this->externalId}/messages",
            ['text' => 'hi']
        )->assertUnauthorized();
    }

    public function test_send_file_returns_401_without_key(): void
    {
        Storage::fake('public');

        $this->postJson(
            "/api/widget/{$this->externalId}/files",
            ['uploaded_file' => UploadedFile::fake()->create('f.pdf', 10)]
        )->assertUnauthorized();
    }

    public function test_get_messages_returns_401_without_key(): void
    {
        $this->getJson("/api/widget/{$this->externalId}/messages")
            ->assertUnauthorized();
    }
}
