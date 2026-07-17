<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\DTOs\AiResponseDto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider extends BaseAiProvider
{
    public function __construct()
    {
        parent::__construct('openai');
    }

    /**
     * Process user message through OpenAI API.
     *
     * @param AiRequestDto $request Request DTO
     *
     * @return AiResponseDto|null AI response DTO
     */
    public function processMessage(AiRequestDto $request): ?AiResponseDto
    {
        try {
            $startedAt = microtime(true);
            $response = $this->makeApiCall($request);

            return $this->parseApiResponse($response, $request, $startedAt);
        } catch (\Throwable $e) {
            Log::channel('app')->error($e->getMessage(), [
                'source' => 'ai_error',
                'user_id' => $request->userId,
                'platform' => $request->platform,
            ]);

            return null;
        }
    }

    /**
     * Make API call to OpenAI.
     *
     * @param AiRequestDto $request Request DTO
     *
     * @return array OpenAI API response
     *
     * @throws \Exception
     */
    private function makeApiCall(AiRequestDto $request): array
    {
        $messages = $this->buildMessages($request);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->connectTimeout(3)
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->post($this->baseUrl . '/chat/completions', [
            'model' => $this->modelName,
            'messages' => $messages,
            'max_tokens' => (int) ($this->config['max_tokens'] ?? 1000),
            'temperature' => (float) ($this->config['temperature'] ?? 0.7),
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: HTTP ' . $response->status());
        }

        return $response->json();
    }

    /**
     * Build messages array for OpenAI API.
     *
     * @param AiRequestDto $request Request DTO
     *
     * @return array Messages array in OpenAI format
     */
    private function buildMessages(AiRequestDto $request): array
    {
        return $this->buildChatMessages($request);
    }

    /**
     * Parse OpenAI API response and create DTO.
     *
     * @param array        $response OpenAI API response
     * @param AiRequestDto $request  Original request
     *
     * @return AiResponseDto AI response DTO
     */
    private function parseApiResponse(array $response, AiRequestDto $request, float $startedAt): AiResponseDto
    {
        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new \RuntimeException('OpenAI API returned an empty response.');
        }
        $usage = $response['usage'] ?? [];

        $parsedContent = $this->parseStructuredResponse($content);

        $confidenceScore = $parsedContent['confidence_score'] ?? 0.8;
        $shouldEscalate = $parsedContent['should_escalate'] ?? $this->shouldEscalate($confidenceScore);
        $aiResponse = $parsedContent['response'] ?? $content;

        return $this->createResponse(
            response: $aiResponse,
            confidenceScore: $confidenceScore,
            shouldEscalate: $shouldEscalate,
            tokensUsed: $usage['total_tokens'] ?? 0,
            responseTime: microtime(true) - $startedAt,
            metadata: [
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
                'model' => $response['model'] ?? null,
                'parsed_content' => $parsedContent,
            ]
        );
    }

    /**
     * Parse structured response from AI.
     *
     * @param string $content AI response text
     *
     * @return array Parsed data with confidence and escalation flag
     */
    private function parseStructuredResponse(string $content): array
    {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $confidenceScore = 0.8;
        $shouldEscalate = false;

        if (preg_match('/confidence[:\s]+(\d+\.?\d*)/i', $content, $matches)) {
            $confidenceScore = (float) $matches[1];
        }

        if (preg_match('/escalat(e|ion)/i', $content)) {
            $shouldEscalate = true;
        }

        return [
            'response' => $content,
            'confidence_score' => $confidenceScore,
            'should_escalate' => $shouldEscalate,
        ];
    }

    /**
     * Check if provider is available and properly configured.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return !empty($this->config['api_key']) && !empty($this->config['base_url']);
    }
}
