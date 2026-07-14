<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Models\AiSupportKnowledgeChunk;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportCaseModeratorService
{
    public const RULES_VERSION = 'KAR-295 / v1 / 03.07.2026';

    /**
     * @return array{checked: int, updated: int, failed: int}
     */
    public function moderatePending(int $limit = 50): array
    {
        $checked = 0;
        $updated = 0;
        $failed = 0;

        AiSupportKnowledgeChunk::query()
            ->where('status', AiSupportKnowledgeChunk::STATUS_REVIEW)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (AiSupportKnowledgeChunk $chunk) use (&$checked, &$updated, &$failed): void {
                $checked++;

                if ($this->moderate($chunk)) {
                    $updated++;
                } else {
                    $failed++;
                }
            });

        return [
            'checked' => $checked,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<int, string> $sourceHashes
     *
     * @return array{checked: int, updated: int, failed: int}
     */
    public function moderateBySourceHashes(array $sourceHashes): array
    {
        $sourceHashes = array_values(array_unique(array_filter($sourceHashes)));
        if ($sourceHashes === []) {
            return ['checked' => 0, 'updated' => 0, 'failed' => 0];
        }

        $checked = 0;
        $updated = 0;
        $failed = 0;

        AiSupportKnowledgeChunk::query()
            ->whereIn('source_hash', $sourceHashes)
            ->where('status', AiSupportKnowledgeChunk::STATUS_REVIEW)
            ->orderBy('id')
            ->get()
            ->each(function (AiSupportKnowledgeChunk $chunk) use (&$checked, &$updated, &$failed): void {
                $checked++;

                if ($this->moderate($chunk)) {
                    $updated++;
                } else {
                    $failed++;
                }
            });

        return [
            'checked' => $checked,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    public function moderate(AiSupportKnowledgeChunk $chunk): bool
    {
        try {
            $raw = $this->requestModeration($chunk);
            $result = $this->parseResult($raw);
            $this->applyResult($chunk, $result);

            return true;
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Support case moderation failed', [
                'source' => 'ai_support_moderation',
                'chunk_id' => $chunk->id,
                'error' => $e->getMessage(),
            ]);

            $this->markAsReview($chunk, 'AI-модератор не вернул корректный JSON: ' . $e->getMessage(), ['invalid_json']);

            return false;
        }
    }

    private function requestModeration(AiSupportKnowledgeChunk $chunk): string
    {
        $settings = app(SettingsService::class);
        $provider = (string) ($settings->get('ai.support_moderator_provider') ?? $settings->get('ai.default_provider') ?? 'deepseek');
        $provider = $provider !== '' ? $provider : 'deepseek';

        $config = $this->providerConfig($provider, $settings);
        if ($config['api_key'] === '' || $config['url'] === '') {
            throw new \RuntimeException("AI-модератор {$provider} не настроен.");
        }

        $response = Http::timeout(40)
            ->withToken($config['api_key'])
            ->acceptJson()
            ->post($config['url'], [
                'model' => $config['model'],
                'messages' => $this->messages($chunk),
                'temperature' => 0.1,
                'max_tokens' => 700,
                'stream' => false,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $content = (string) $response->json('choices.0.message.content', '');
        if (trim($content) === '') {
            throw new \RuntimeException('пустой ответ модели');
        }

        return $content;
    }

    /**
     * @return array{api_key: string, url: string, model: string}
     */
    private function providerConfig(string $provider, SettingsService $settings): array
    {
        if ($provider === 'openai') {
            $baseUrl = rtrim((string) ($settings->get('ai.openai_base_url') ?? 'https://api.openai.com/v1'), '/');

            return [
                'api_key' => (string) ($settings->get('ai.openai_api_key') ?? ''),
                'url' => $this->completionUrl($baseUrl),
                'model' => (string) ($settings->get('ai.support_moderator_model') ?? $settings->get('ai.openai_model') ?? 'gpt-4o-mini'),
            ];
        }

        $baseUrl = rtrim((string) ($settings->get('ai.deepseek_base_url') ?? 'https://api.deepseek.com/v1'), '/');

        return [
            'api_key' => (string) ($settings->get('ai.deepseek_client_secret') ?? ''),
            'url' => $this->completionUrl($baseUrl),
            'model' => (string) ($settings->get('ai.support_moderator_model') ?? $settings->get('ai.deepseek_model') ?? 'deepseek-chat'),
        ];
    }

    private function completionUrl(string $baseUrl): string
    {
        if ($baseUrl === '') {
            return '';
        }

        return str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl . '/chat/completions';
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(AiSupportKnowledgeChunk $chunk): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'rules_version' => self::RULES_VERSION,
                    'case_id' => $chunk->id,
                    'question' => $chunk->question,
                    'answer' => $chunk->answer,
                    'keywords' => $chunk->keywords,
                    'source_metadata' => $chunk->source_metadata,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты AI-модератор базы support-диалогов RelaxaClub.
Твоя задача — оценить, можно ли использовать кейс как пример для будущих AI-ответов.

Правила:
1. Вопрос клиента и ответ оператора должны быть связаны между собой.
2. Если вопрос или ответ неполные, смешаны с другим диалогом, содержат мусор, команды, контактные карточки или устаревшие цены — статус review или disabled.
3. Если ответ полезный, конкретный, без опасных обещаний и не выглядит дублем — статус active.
4. Если есть сомнения — статус review.
5. Если кейс явно вредный или бессмысленный — статус disabled.
6. Не выдумывай данные. Оценивай только переданный кейс.

Верни только JSON:
{
  "status": "active|review|disabled",
  "quality_score": 0.0,
  "reason": "короткая причина на русском",
  "risks": ["список рисков"],
  "duplicate_group_key": "строка или null",
  "recommended_action": "activate|review|disable|delete"
}
PROMPT;
    }

    /**
     * @return array{status: string, quality_score: float, reason: string, risks: array<int, string>, duplicate_group_key: string|null, recommended_action: string}
     */
    private function parseResult(string $raw): array
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new \RuntimeException('ответ не является JSON-объектом');
        }

        $status = (string) ($data['status'] ?? AiSupportKnowledgeChunk::STATUS_REVIEW);
        if (! in_array($status, AiSupportKnowledgeChunk::statuses(), true)) {
            $status = AiSupportKnowledgeChunk::STATUS_REVIEW;
        }

        $risks = $data['risks'] ?? [];
        if (! is_array($risks)) {
            $risks = ['invalid_risks'];
        }

        return [
            'status' => $status,
            'quality_score' => max(0.0, min(1.0, (float) ($data['quality_score'] ?? 0.0))),
            'reason' => trim((string) ($data['reason'] ?? 'AI-модератор не указал причину.')),
            'risks' => array_values(array_map(static fn (mixed $risk): string => mb_substr((string) $risk, 0, 80), $risks)),
            'duplicate_group_key' => filled($data['duplicate_group_key'] ?? null) ? mb_substr((string) $data['duplicate_group_key'], 0, 255) : null,
            'recommended_action' => (string) ($data['recommended_action'] ?? 'review'),
        ];
    }

    /**
     * @param array{status: string, quality_score: float, reason: string, risks: array<int, string>, duplicate_group_key: string|null, recommended_action: string} $result
     */
    private function applyResult(AiSupportKnowledgeChunk $chunk, array $result): void
    {
        $chunk->status = $result['status'];
        $chunk->is_active = $result['status'] === AiSupportKnowledgeChunk::STATUS_ACTIVE;
        $chunk->moderation_reason = $result['reason'];
        $chunk->moderation_risks = $result['risks'];
        $chunk->duplicate_group_key = $result['duplicate_group_key'];
        $chunk->source_metadata = array_merge($chunk->source_metadata ?? [], [
            'moderation' => [
                'rules_version' => self::RULES_VERSION,
                'quality_score' => $result['quality_score'],
                'recommended_action' => $result['recommended_action'],
                'checked_at' => now()->toDateTimeString(),
            ],
        ]);
        $chunk->save();
    }

    /**
     * @param array<int, string> $risks
     */
    private function markAsReview(AiSupportKnowledgeChunk $chunk, string $reason, array $risks): void
    {
        $chunk->status = AiSupportKnowledgeChunk::STATUS_REVIEW;
        $chunk->is_active = false;
        $chunk->moderation_reason = mb_substr($reason, 0, 1000);
        $chunk->moderation_risks = $risks;
        $chunk->save();
    }
}
