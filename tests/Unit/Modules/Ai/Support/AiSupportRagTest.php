<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai\Support;

use App\Models\AiSupportKnowledgeChunk;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Support\AiSupportContextService;
use App\Modules\Ai\Support\SupportArchiveImportService;
use App\Modules\Ai\Support\SupportArchiveParser;
use App\Modules\Ai\Support\SupportCaseModeratorService;
use App\Modules\Ai\Support\SupportCurrentDialogImportService;
use App\Modules\Ai\Support\SupportEmbeddingService;
use App\Modules\Ai\Support\SupportRagSearchService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSupportRagTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_reads_telegram_html_and_detects_roles(): void
    {
        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'Full video?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'Bro, I have not found the full video yet.'),
            $this->messageHtml('message3', 'Умид Каршиев', '05.02.2026 10:09:10 UTC+03:00', '/close'),
        ]);

        $messages = (new SupportArchiveParser())->parseDirectory($directory);

        $this->assertCount(3, $messages);
        $this->assertSame('client', $messages[0]['sender_role']);
        $this->assertSame('operator', $messages[1]['sender_role']);
        $this->assertTrue($messages[2]['is_noise']);
    }

    public function test_import_is_idempotent_and_builds_chunks(): void
    {
        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'How much BroSpace?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'BroSpace costs 500 ₽ for 1 month.'),
        ]);

        $service = new SupportArchiveImportService(new SupportArchiveParser(), new NullEmbeddingService());
        $dryRun = $service->import($directory, false);

        $this->assertTrue($dryRun['dry_run']);
        $this->assertSame(2, $dryRun['messages_count']);
        $this->assertSame(1, $dryRun['chunks_count']);
        $this->assertDatabaseCount('ai_support_messages', 0);

        $service->import($directory, true);
        $service->import($directory, true);

        $this->assertDatabaseCount('ai_support_messages', 2);
        $this->assertDatabaseCount('ai_support_knowledge_chunks', 1);
    }

    public function test_import_report_counts_unique_chunks(): void
    {
        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'Same question?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'Same answer.'),
            $this->messageHtml('message3', '⁉️Relaxa.Club Support Bot', '05.02.2026 10:09:10 UTC+03:00', 'Same question?'),
            $this->messageHtml('message4', 'Ne0soul', '05.02.2026 10:10:10 UTC+03:00', 'Same answer.'),
        ]);

        $service = new SupportArchiveImportService(new SupportArchiveParser(), new NullEmbeddingService());
        $result = $service->import($directory, false);

        $this->assertSame(1, $result['chunks_count']);
    }

    public function test_reimport_keeps_disabled_chunk_disabled(): void
    {
        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'How much BroSpace?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'BroSpace costs 500 ₽ for 1 month.'),
        ]);

        $service = new SupportArchiveImportService(new SupportArchiveParser(), new NullEmbeddingService());
        $service->import($directory, true);

        $chunk = AiSupportKnowledgeChunk::firstOrFail();
        $chunk->update(['is_active' => false]);

        $service->import($directory, true);

        $this->assertFalse($chunk->fresh()->is_active);
    }

    public function test_reimport_keeps_existing_embedding_when_new_embedding_unavailable(): void
    {
        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'How much BroSpace?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'BroSpace costs 500 ₽ for 1 month.'),
        ]);

        $service = new SupportArchiveImportService(new SupportArchiveParser(), new NullEmbeddingService());
        $service->import($directory, true);

        $chunk = AiSupportKnowledgeChunk::firstOrFail();
        $chunk->update(['embedding' => [0.1, 0.2]]);

        $service->import($directory, true);

        $this->assertSame([0.1, 0.2], $chunk->fresh()->embedding);
    }

    public function test_rag_returns_relevant_cases_without_embeddings(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'brospace-case',
            'question' => 'How much BroSpace?',
            'answer' => 'BroSpace costs 500 ₽ for 1 month.',
            'keywords' => ['brospace', 'costs'],
            'is_active' => true,
        ]);

        AiSupportKnowledgeChunk::create([
            'source_hash' => 'other-case',
            'question' => 'Hello',
            'answer' => 'Hello, how can I help?',
            'keywords' => ['hello'],
            'is_active' => true,
        ]);

        $service = new SupportRagSearchService(new NullEmbeddingService());
        $items = $service->search('BroSpace price');

        $this->assertCount(1, $items);
        $this->assertSame('brospace-case', $items->first()->source_hash);
    }

    public function test_rag_uses_only_active_moderated_cases(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'review-brospace-case',
            'question' => 'How much BroSpace?',
            'answer' => 'Old unverified BroSpace answer.',
            'keywords' => ['brospace'],
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_REVIEW,
        ]);

        AiSupportKnowledgeChunk::create([
            'source_hash' => 'active-brospace-case',
            'question' => 'How much BroSpace?',
            'answer' => 'Verified BroSpace answer.',
            'keywords' => ['brospace'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $service = new SupportRagSearchService(new NullEmbeddingService());
        $items = $service->search('BroSpace price');

        $this->assertCount(1, $items);
        $this->assertSame('active-brospace-case', $items->first()->source_hash);
    }

    public function test_current_dialog_import_groups_client_and_operator_blocks_per_dialog(): void
    {
        $firstDialog = BotUser::create([
            'chat_id' => 1001,
            'platform' => 'telegram',
        ]);
        $secondDialog = BotUser::create([
            'chat_id' => 1002,
            'platform' => 'telegram',
        ]);

        $this->createMessage($firstDialog->id, 'incoming', 'Hello');
        $this->createMessage($firstDialog->id, 'incoming', 'How much BroSpace?');
        $this->createMessage($firstDialog->id, 'outgoing', 'Hi.');
        $this->createMessage($firstDialog->id, 'outgoing', 'BroSpace costs 500 ₽.');

        $this->createMessage($secondDialog->id, 'incoming', 'Elite price?');
        $this->createMessage($secondDialog->id, 'outgoing', 'Elite costs 2000 ₽.');

        $service = new SupportCurrentDialogImportService(new NullEmbeddingService());
        $dryRun = $service->import(false);

        $this->assertTrue($dryRun['dry_run']);
        $this->assertSame(2, $dryRun['dialogs_count']);
        $this->assertSame(6, $dryRun['messages_count']);
        $this->assertSame(2, $dryRun['chunks_count']);
        $this->assertDatabaseCount('ai_support_knowledge_chunks', 0);

        $service->import(true);
        $service->import(true);

        $this->assertDatabaseCount('ai_support_knowledge_chunks', 2);

        $brospace = AiSupportKnowledgeChunk::where('question', 'like', '%BroSpace%')->firstOrFail();
        $this->assertSame("Hello\nHow much BroSpace?", $brospace->question);
        $this->assertSame("Hi.\nBroSpace costs 500 ₽.", $brospace->answer);
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_REVIEW, $brospace->status);
        $this->assertFalse($brospace->is_active);
        $this->assertSame($firstDialog->id, $brospace->source_metadata['bot_user_id']);
        $this->assertSame([1, 2], $brospace->source_metadata['client_message_ids']);
        $this->assertSame([3, 4], $brospace->source_metadata['operator_message_ids']);
    }

    public function test_support_case_moderator_applies_strict_json_result(): void
    {
        $this->configureDeepSeekModerator();
        Http::fake([
            'https://api.deepseek.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'status' => 'active',
                            'quality_score' => 0.92,
                            'reason' => 'Связанный вопрос и полезный ответ.',
                            'risks' => [],
                            'duplicate_group_key' => null,
                            'recommended_action' => 'activate',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'moderation-active-case',
            'question' => 'How much BroSpace?',
            'answer' => 'BroSpace costs 500 ₽.',
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_REVIEW,
        ]);

        $result = (new SupportCaseModeratorService())->moderatePending(10);

        $this->assertSame(['checked' => 1, 'updated' => 1, 'failed' => 0], $result);
        $chunk->refresh();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_ACTIVE, $chunk->status);
        $this->assertTrue($chunk->is_active);
        $this->assertSame('Связанный вопрос и полезный ответ.', $chunk->moderation_reason);
        $this->assertSame('activate', $chunk->source_metadata['moderation']['recommended_action']);
    }

    public function test_support_case_moderator_keeps_invalid_ai_response_on_review(): void
    {
        $this->configureDeepSeekModerator();
        Http::fake([
            'https://api.deepseek.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'not json'],
                ]],
            ]),
        ]);

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'moderation-invalid-case',
            'question' => 'Mixed question?',
            'answer' => 'Mixed answer.',
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_REVIEW,
        ]);

        $result = (new SupportCaseModeratorService())->moderatePending(10);

        $this->assertSame(['checked' => 1, 'updated' => 0, 'failed' => 1], $result);
        $chunk->refresh();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_REVIEW, $chunk->status);
        $this->assertFalse($chunk->is_active);
        $this->assertSame(['invalid_json'], $chunk->moderation_risks);
    }

    public function test_ai_support_context_is_compact(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'long-case',
            'question' => str_repeat('BroSpace question ', 400),
            'answer' => str_repeat('BroSpace answer ', 400),
            'keywords' => ['brospace'],
            'is_active' => true,
        ]);

        $contextService = new AiSupportContextService(new SupportRagSearchService(new NullEmbeddingService()));
        $context = $contextService->buildContextMessage('BroSpace');

        $this->assertNotNull($context);
        $this->assertSame('system', $context['role']);
        $this->assertLessThanOrEqual(5000, mb_strlen($context['content']));
    }

    public function test_fine_tune_export_creates_valid_jsonl(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'export-case',
            'question' => 'Question?',
            'answer' => 'Answer.',
            'keywords' => ['question'],
            'is_active' => true,
        ]);

        $path = storage_path('app/test-ai-support-finetune.jsonl');
        File::delete($path);

        Artisan::call('ai:support-export-finetune', ['file' => $path]);

        $line = trim((string) File::get($path));
        $decoded = json_decode($line, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Question?', $decoded['messages'][1]['content']);
        $this->assertSame('Answer.', $decoded['messages'][2]['content']);
    }

    public function test_support_evaluation_command_checks_expected_and_forbidden_markers(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'eval-brospace',
            'question' => 'How much BroSpace?',
            'answer' => 'BroSpace costs 500 ₽.',
            'keywords' => ['brospace'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        AiSupportKnowledgeChunk::create([
            'source_hash' => 'eval-massage-disabled',
            'question' => 'Massage price?',
            'answer' => 'Massage costs 1000 ₽.',
            'keywords' => ['massage'],
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_DISABLED,
        ]);

        $path = storage_path('app/test-ai-support-evaluation.json');
        File::put($path, json_encode([[
            'name' => 'BroSpace eval',
            'query' => 'BroSpace price',
            'expected_keywords' => ['brospace'],
            'forbidden_keywords' => ['massage'],
            'min_results' => 1,
        ]], JSON_UNESCAPED_UNICODE));

        $exitCode = Artisan::call('ai:support-evaluate', ['file' => $path]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Passed: 1', Artisan::output());
    }

    public function test_rag_prefers_ru_canonical_but_keeps_original_in_context(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'ru-canonical-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'question_ru' => 'Сколько стоит Elite?',
            'answer_ru' => 'Elite стоит 2000 ₽.',
            'ai_instruction' => 'Не обещай скидку без подтверждения.',
            'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'answer_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'keywords' => ['elite'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $contextService = new AiSupportContextService(new SupportRagSearchService(new NullEmbeddingService()));
        $context = $contextService->buildContextMessage('стоимость Elite');

        $this->assertNotNull($context);
        $this->assertStringContainsString('Клиент RU: Сколько стоит Elite?', $context['content']);
        $this->assertStringContainsString('Оператор RU: Elite стоит 2000 ₽.', $context['content']);
        $this->assertStringContainsString('Клиент оригинал: How much Elite?', $context['content']);
        $this->assertStringContainsString('Инструкция AI: Не обещай скидку без подтверждения.', $context['content']);
    }

    public function test_rag_ignores_failed_ru_canonical_for_search_but_keeps_original_fallback(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'elite-with-bad-ru',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'question_ru' => 'Сколько стоит BroSpace?',
            'answer_ru' => 'BroSpace стоит 500 ₽.',
            'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_FAILED,
            'answer_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_FAILED,
            'keywords' => [],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $service = new SupportRagSearchService(new NullEmbeddingService());

        $this->assertCount(0, $service->search('BroSpace price'));
        $this->assertSame('elite-with-bad-ru', $service->search('Elite price')->first()?->source_hash);
    }

    public function test_support_context_adds_safe_default_instruction_when_case_instruction_is_empty(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'default-instruction-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'question_ru' => 'Сколько стоит Elite?',
            'answer_ru' => 'Elite стоит 2000 ₽.',
            'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'answer_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'keywords' => ['elite'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $contextService = new AiSupportContextService(new SupportRagSearchService(new NullEmbeddingService()));
        $context = $contextService->buildContextMessage('стоимость Elite');

        $this->assertNotNull($context);
        $this->assertStringContainsString('Инструкция AI: Используй этот кейс только как пример похожего диалога.', $context['content']);
        $this->assertStringContainsString('Не обещай цены, скидки, возвраты, продления, доступы', $context['content']);
    }

    public function test_support_evaluation_checks_ru_canonical_and_instruction_text(): void
    {
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'eval-ru-canonical',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'question_ru' => 'Сколько стоит Elite?',
            'answer_ru' => 'Elite стоит 2000 ₽.',
            'ai_instruction' => 'Передай специалисту, если клиент просит скидку.',
            'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'answer_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED,
            'keywords' => ['elite'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $path = storage_path('app/test-ai-support-evaluation-ru.json');
        File::put($path, json_encode([[
            'name' => 'Elite RU canonical eval',
            'query' => 'стоимость Elite',
            'expected_keywords' => ['стоит 2000', 'передай специалисту'],
            'forbidden_keywords' => ['brospace'],
            'min_results' => 1,
        ]], JSON_UNESCAPED_UNICODE));

        $exitCode = Artisan::call('ai:support-evaluate', ['file' => $path]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Passed: 1', Artisan::output());
    }

    public function test_support_canonicalizer_does_not_overwrite_manual_ru_without_force(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'manual-protected-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'question_ru' => 'Ручной русский смысл',
            'question_ru_manually_edited' => true,
            'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_MANUAL_EDITED,
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $result = app(\App\Modules\Ai\Support\SupportCaseCanonicalizerService::class)->canonicalize($chunk, 'question');

        $this->assertFalse($result->success);
        $this->assertSame('manual_protected', $result->errorCode);
        $this->assertSame('Ручной русский смысл', $chunk->fresh()->question_ru);
    }

    public function test_support_canonicalizer_marks_failed_translation(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['missing-provider']);

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'failed-canonical-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        $result = app(\App\Modules\Ai\Support\SupportCaseCanonicalizerService::class)->canonicalize($chunk, 'question');

        $this->assertFalse($result->success);
        $chunk->refresh();
        $this->assertSame(AiSupportKnowledgeChunk::TRANSLATION_FAILED, $chunk->question_translation_status);
        $this->assertNotEmpty($chunk->question_translation_error);
    }

    /**
     * @param array<int, string> $messages
     */
    private function makeArchive(array $messages): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-support-test-' . uniqid();
        File::makeDirectory($directory, 0755, true);
        File::put($directory . DIRECTORY_SEPARATOR . 'messages.html', '<html><body>' . implode("\n", $messages) . '</body></html>');

        return $directory;
    }

    private function messageHtml(string $id, string $sender, string $date, string $text): string
    {
        return <<<HTML
<div class="message default clearfix" id="{$id}">
  <div class="body">
    <div class="pull_right date details" title="{$date}">10:00</div>
    <div class="from_name">{$sender}</div>
    <div class="text">{$text}</div>
  </div>
</div>
HTML;
    }

    private function createMessage(int $botUserId, string $type, string $text): Message
    {
        return Message::create([
            'bot_user_id' => $botUserId,
            'platform' => 'telegram',
            'message_type' => $type,
            'from_id' => 0,
            'to_id' => 0,
            'text' => $text,
        ]);
    }

    private function configureDeepSeekModerator(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('ai.support_moderator_provider', 'deepseek');
        $settings->set('ai.support_moderator_model', 'deepseek-chat');
        $settings->set('ai.deepseek_client_secret', 'test-secret');
        $settings->set('ai.deepseek_base_url', 'https://api.deepseek.test/v1');
    }
}

class NullEmbeddingService extends SupportEmbeddingService
{
    public function embed(string $text): ?array
    {
        return null;
    }
}
