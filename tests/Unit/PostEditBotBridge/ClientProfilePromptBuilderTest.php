<?php

namespace Tests\Unit\PostEditBotBridge;

use App\Modules\PostEditBotBridge\Services\ClientProfilePromptBuilder;
use PHPUnit\Framework\TestCase;

class ClientProfilePromptBuilderTest extends TestCase
{
    public function test_missing_profile_forbids_inventing_subscription_data(): void
    {
        $prompt = (new ClientProfilePromptBuilder())->build([
            'found' => false,
            'notes' => ['Клиент не найден'],
        ]);

        $this->assertIsString($prompt);
        $this->assertStringContainsString('не найден', $prompt);
        $this->assertStringContainsString('Не выдумывай подписки', $prompt);
    }

    public function test_found_profile_contains_subscription_and_payment_counts(): void
    {
        $prompt = (new ClientProfilePromptBuilder())->build([
            'found' => true,
            'client' => [
                'id' => 'client-1',
                'tgId' => '123',
                'tgUsername' => 'client',
                'email' => 'client@example.test',
            ],
            'currentSubscriptions' => [['id' => 'sub-active']],
            'pastSubscriptions' => [['id' => 'sub-old']],
            'payments' => [['id' => 'pay-1'], ['id' => 'pay-2']],
        ]);

        $this->assertIsString($prompt);
        $this->assertStringContainsString('client@example.test', $prompt);
        $this->assertStringContainsString('Активные/отложенные подписки: 1', $prompt);
        $this->assertStringContainsString('Прошлые подписки: 1', $prompt);
        $this->assertStringContainsString('Последние платежи: 2', $prompt);
        $this->assertStringContainsString('эскалируй', $prompt);
    }
}
