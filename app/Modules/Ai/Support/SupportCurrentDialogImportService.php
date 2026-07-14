<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Models\AiSupportImportBatch;
use App\Models\AiSupportKnowledgeChunk;
use App\Models\BotUser;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportCurrentDialogImportService
{
    public function __construct(
        private readonly SupportEmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{dialogs_count: int, messages_count: int, chunks_count: int, created_chunks_count: int, created_source_hashes: array<int, string>, dry_run: bool}
     */
    public function import(bool $activate = false, ?int $limitDialogs = null): array
    {
        $dialogs = $this->dialogs($limitDialogs);
        $chunks = [];
        $messagesCount = 0;

        foreach ($dialogs as $dialog) {
            $messages = $this->messagesForDialog($dialog);
            $messagesCount += $messages->count();
            $chunks = array_merge($chunks, $this->buildChunksForDialog($dialog, $messages));
        }

        $chunks = $this->uniqueChunks($chunks);
        $result = [
            'dialogs_count' => $dialogs->count(),
            'messages_count' => $messagesCount,
            'chunks_count' => count($chunks),
            'created_chunks_count' => 0,
            'created_source_hashes' => [],
            'dry_run' => ! $activate,
        ];

        if (! $activate) {
            return $result;
        }

        $createdSourceHashes = DB::transaction(function () use ($chunks, $result): array {
            $batch = AiSupportImportBatch::create([
                'source_path' => 'current-dialogs',
                'mode' => 'current-dialogs',
                'messages_count' => $result['messages_count'],
                'chunks_count' => count($chunks),
                'metadata' => [
                    'source' => 'messages',
                    'grouping' => 'bot_user_id_consecutive_roles',
                ],
            ]);

            $createdSourceHashes = $this->upsertChunks($chunks);

            $batch->update(['chunks_count' => count($createdSourceHashes)]);

            return $createdSourceHashes;
        });

        $result['created_chunks_count'] = count($createdSourceHashes);
        $result['created_source_hashes'] = $createdSourceHashes;

        return $result;
    }

    /**
     * @return EloquentCollection<int, BotUser>
     */
    private function dialogs(?int $limitDialogs): EloquentCollection
    {
        $query = BotUser::query()
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.bot_user_id', 'bot_users.id')
                    ->whereNotNull('messages.text');
            })
            ->orderByDesc('id');

        if ($limitDialogs !== null) {
            $query->limit($limitDialogs);
        }

        return $query->get();
    }

    /**
     * @return EloquentCollection<int, Message>
     */
    private function messagesForDialog(BotUser $dialog): EloquentCollection
    {
        return Message::query()
            ->where('bot_user_id', $dialog->id)
            ->whereNotNull('text')
            ->whereIn('message_type', ['incoming', 'outgoing'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param EloquentCollection<int, Message> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildChunksForDialog(BotUser $dialog, EloquentCollection $messages): array
    {
        $chunks = [];
        $clientBuffer = [];
        $operatorBuffer = [];
        $clientIds = [];
        $operatorIds = [];
        $firstAt = null;
        $lastAt = null;

        foreach ($messages as $message) {
            $text = trim((string) $message->text);
            if ($text === '' || $this->looksLikeCommand($text)) {
                continue;
            }

            if ($message->message_type === 'incoming') {
                if ($clientBuffer !== [] && $operatorBuffer !== []) {
                    $chunks[] = $this->makeChunk($dialog, $clientBuffer, $operatorBuffer, $clientIds, $operatorIds, $firstAt, $lastAt);
                    $clientBuffer = [];
                    $operatorBuffer = [];
                    $clientIds = [];
                    $operatorIds = [];
                    $firstAt = null;
                    $lastAt = null;
                }

                $clientBuffer[] = $text;
                $clientIds[] = $message->id;
                $firstAt ??= $message->created_at?->toDateTimeString();
                $lastAt = $message->created_at?->toDateTimeString();

                continue;
            }

            if ($message->message_type === 'outgoing' && $clientBuffer !== []) {
                $operatorBuffer[] = $text;
                $operatorIds[] = $message->id;
                $lastAt = $message->created_at?->toDateTimeString();
            }
        }

        if ($clientBuffer !== [] && $operatorBuffer !== []) {
            $chunks[] = $this->makeChunk($dialog, $clientBuffer, $operatorBuffer, $clientIds, $operatorIds, $firstAt, $lastAt);
        }

        return $chunks;
    }

    /**
     * @param array<int, string> $clientBuffer
     * @param array<int, string> $operatorBuffer
     * @param array<int, int>    $clientIds
     * @param array<int, int>    $operatorIds
     *
     * @return array<string, mixed>
     */
    private function makeChunk(
        BotUser $dialog,
        array $clientBuffer,
        array $operatorBuffer,
        array $clientIds,
        array $operatorIds,
        ?string $firstAt,
        ?string $lastAt,
    ): array {
        $question = trim(implode("\n", $clientBuffer));
        $answer = trim(implode("\n", $operatorBuffer));

        return [
            'source_hash' => sha1('current-dialog:' . $dialog->id . ':' . implode(',', $clientIds) . ':' . implode(',', $operatorIds)),
            'question' => mb_substr($question, 0, 4000),
            'answer' => mb_substr($answer, 0, 4000),
            'keywords' => $this->keywords($question . ' ' . $answer),
            'priority' => 100,
            'first_message_at' => $firstAt,
            'last_message_at' => $lastAt,
            'source_metadata' => [
                'source' => 'current-dialogs',
                'bot_user_id' => $dialog->id,
                'platform' => $dialog->platform,
                'chat_id' => $dialog->chat_id,
                'topic_id' => $dialog->topic_id,
                'client_message_ids' => $clientIds,
                'operator_message_ids' => $operatorIds,
                'admin_url' => route('admin.chats', ['bot_user_id' => $dialog->id], false),
            ],
        ];
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
                'source_metadata' => $chunk['source_metadata'],
            ];

            $payload['embedding'] = $embedding;

            $model->fill($payload);
            $model->is_active = false;
            $model->status = AiSupportKnowledgeChunk::STATUS_REVIEW;
            $model->moderation_reason = 'Кейс собран из текущего диалога и ждёт AI-модерации.';
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

    private function looksLikeCommand(string $text): bool
    {
        return Str::startsWith($text, '/');
    }
}
