<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

class AiSupportContextService
{
    private const MAX_CONTEXT_CHARS = 5000;

    public function __construct(private readonly SupportRagSearchService $searchService)
    {
    }

    /**
     * @return array{role: string, content: string}|null
     */
    public function buildContextMessage(string $message): ?array
    {
        $chunks = $this->searchService->search($message, 4);
        if ($chunks->isEmpty()) {
            return null;
        }

        $content = "Похожие старые обращения support. Это примеры, а не гарантированная истина. Используй их только если они подходят к текущему вопросу; цены, ссылки, доступы, компенсации и статусы не выдумывай. Если кейс похож, но требует ручного действия оператора, передай вопрос специалисту.\n\n";

        foreach ($chunks as $index => $chunk) {
            $number = $index + 1;
            $content .= "### Support-кейс {$number}\n";
            $content .= 'Клиент RU: ' . $chunk->canonicalQuestion() . "\n";
            $content .= 'Оператор RU: ' . $chunk->canonicalAnswer() . "\n";
            $content .= 'Клиент оригинал: ' . $chunk->originalQuestion() . "\n";
            $content .= 'Оператор оригинал: ' . $chunk->originalAnswer() . "\n";
            $content .= 'Инструкция AI: ' . $chunk->effectiveAiInstruction() . "\n";
            $content .= "Ограничения: не выдумывай цены, ссылки, доступы, компенсации и статусы, если их нет в контексте.\n\n";
        }

        $content = mb_substr(trim($content), 0, self::MAX_CONTEXT_CHARS);

        return [
            'role' => 'system',
            'content' => $content,
        ];
    }
}
