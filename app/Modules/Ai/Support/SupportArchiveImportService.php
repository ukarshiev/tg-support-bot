<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Models\AiSupportImportBatch;
use App\Models\AiSupportKnowledgeChunk;
use App\Models\AiSupportMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportArchiveImportService
{
    public function __construct(
        private readonly SupportArchiveParser $parser,
        private readonly SupportEmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{messages_count: int, chunks_count: int, created_chunks_count: int, created_source_hashes: array<int, string>, dry_run: bool, files: array<int, string>}
     */
    public function import(string $directory, bool $activate = false): array
    {
        $messages = $this->parser->parseDirectory($directory);
        $chunks = $this->uniqueChunks($this->buildChunks($messages));

        $result = [
            'messages_count' => count($messages),
            'chunks_count' => count($chunks),
            'created_chunks_count' => 0,
            'created_source_hashes' => [],
            'dry_run' => ! $activate,
            'files' => array_values(array_unique(array_map(static fn (array $row): string => $row['source_file'], $messages))),
        ];

        if (! $activate) {
            return $result;
        }

        $createdSourceHashes = DB::transaction(function () use ($directory, $messages, $chunks): array {
            $batch = AiSupportImportBatch::create([
                'source_path' => $directory,
                'mode' => 'activate',
                'messages_count' => count($messages),
                'chunks_count' => count($chunks),
                'metadata' => [
                    'parser' => 'telegram_html',
                    'embedding' => 'optional_json',
                ],
            ]);

            foreach ($messages as $message) {
                AiSupportMessage::updateOrCreate(
                    [
                        'source_file' => $message['source_file'],
                        'telegram_message_id' => $message['telegram_message_id'],
                    ],
                    [
                        'message_datetime' => $message['message_datetime'],
                        'sender_name' => $message['sender_name'],
                        'sender_role' => $message['sender_role'],
                        'text' => $message['text'],
                        'is_noise' => $message['is_noise'],
                    ]
                );
            }

            $createdSourceHashes = $this->upsertChunks($chunks);

            $batch->update(['chunks_count' => count($createdSourceHashes)]);

            return $createdSourceHashes;
        });

        $result['created_chunks_count'] = count($createdSourceHashes);
        $result['created_source_hashes'] = $createdSourceHashes;

        return $result;
    }

    /**
     * @return array{chunks_count: int}
     */
    public function rebuildChunksFromStoredMessages(): array
    {
        $storedMessages = AiSupportMessage::query()
            ->orderBy('message_datetime')
            ->orderBy('id')
            ->get();

        $messages = [];
        foreach ($storedMessages as $message) {
            /** @var \Illuminate\Support\Carbon|null $messageDatetime */
            $messageDatetime = $message->getAttribute('message_datetime');
            $messages[] = [
                'source_file' => $message->source_file,
                'telegram_message_id' => $message->telegram_message_id,
                'message_datetime' => $messageDatetime?->toDateTimeString(),
                'sender_name' => $message->sender_name,
                'sender_role' => $message->sender_role,
                'text' => $message->text,
                'is_noise' => (bool) $message->is_noise,
            ];
        }

        $chunks = $this->uniqueChunks($this->buildChunks($messages));
        $this->upsertChunks($chunks);

        return ['chunks_count' => count($chunks)];
    }

    /**
     * @param array<int, array{source_file: string, telegram_message_id: string, message_datetime: string|null, sender_name: string, sender_role: string, text: string, is_noise: bool}> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildChunks(array $messages): array
    {
        $chunks = [];
        $clientBuffer = [];
        $firstAt = null;
        $lastAt = null;

        foreach ($messages as $message) {
            if ($message['is_noise']) {
                continue;
            }

            if ($message['sender_role'] === 'client') {
                $clientBuffer[] = $message['text'];
                $firstAt ??= $message['message_datetime'];
                $lastAt = $message['message_datetime'];
                continue;
            }

            if ($message['sender_role'] !== 'operator' || $clientBuffer === []) {
                continue;
            }

            $question = trim(implode("\n", $clientBuffer));
            $answer = trim($message['text']);

            if ($question !== '' && $answer !== '' && ! $this->looksLikeCommand($answer)) {
                $chunks[] = $this->makeChunk($question, $answer, $firstAt, $message['message_datetime'] ?? $lastAt);
            }

            $clientBuffer = [];
            $firstAt = null;
            $lastAt = null;
        }

        return $chunks;
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    /**
     * @return array<int, string>
     */
    private function upsertChunks(array $chunks): array
    {
        $createdSourceHashes = [];

        foreach ($chunks as $chunk) {
            $textForEmbedding = $chunk['question'] . "\n" . $chunk['answer'];
            $embedding = $this->embeddingService->embed($textForEmbedding);

            $model = AiSupportKnowledgeChunk::firstOrNew(['source_hash' => $chunk['source_hash']]);
            if ($model->exists) {
                continue;
            }

            $payload = [
                'question' => $chunk['question'],
                'answer' => $chunk['answer'],
                'question_original' => $chunk['question'],
                'answer_original' => $chunk['answer'],
                'source_locale' => 'auto',
                'target_locale' => 'ru',
                'question_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_PENDING,
                'answer_translation_status' => AiSupportKnowledgeChunk::TRANSLATION_PENDING,
                'keywords' => $chunk['keywords'],
                'priority' => $chunk['priority'],
                'first_message_at' => $chunk['first_message_at'],
                'last_message_at' => $chunk['last_message_at'],
            ];

            $payload['embedding'] = $embedding;

            $model->fill($payload);
            $model->is_active = false;
            $model->status = AiSupportKnowledgeChunk::STATUS_REVIEW;
            $model->moderation_reason = 'Кейс импортирован из архива и ждёт AI-модерации.';
            $model->save();
            $createdSourceHashes[] = $model->source_hash;
        }

        return $createdSourceHashes;
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     *
     * @return array<int, array<string, mixed>>
     */
    private function uniqueChunks(array $chunks): array
    {
        return collect($chunks)
            ->unique(static fn (array $chunk): string => (string) $chunk['source_hash'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function makeChunk(string $question, string $answer, ?string $firstAt, ?string $lastAt): array
    {
        return [
            'source_hash' => sha1($question . "\n---\n" . $answer),
            'question' => mb_substr($question, 0, 4000),
            'answer' => mb_substr($answer, 0, 4000),
            'keywords' => $this->keywords($question . ' ' . $answer),
            'priority' => 100,
            'first_message_at' => $firstAt,
            'last_message_at' => $lastAt,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_-]{4,}/u', mb_strtolower($text), $matches);

        return collect($matches[0])
            ->reject(static fn (string $term): bool => in_array($term, ['this', 'that', 'with', 'from', 'have', 'если', 'что', 'как', 'для', 'или', 'это'], true))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(12)
            ->values()
            ->all();
    }

    private function looksLikeCommand(string $answer): bool
    {
        return Str::startsWith(trim($answer), '/');
    }
}
