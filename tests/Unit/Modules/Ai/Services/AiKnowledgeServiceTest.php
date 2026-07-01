<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai\Services;

use App\Models\AiKnowledgeItem;
use App\Modules\Ai\Services\AiKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiKnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_relevant_items_by_title_keywords_and_content(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'Цена BroSpace: 500 ₽ за 1 месяц.',
            'keywords' => ['brospace', 'броспейс'],
            'priority' => 10,
        ]);

        AiKnowledgeItem::create([
            'slug' => 'faq-hashtags',
            'title' => 'Хештеги',
            'content' => 'Хештеги помогают искать контент.',
            'keywords' => ['поиск', 'хештеги'],
            'priority' => 20,
        ]);

        $items = (new AiKnowledgeService())->findRelevant('Сколько стоит BroSpace?');

        $this->assertCount(1, $items);
        $this->assertSame('product-brospace', $items->first()->slug);
    }

    public function test_builds_compact_system_context_message(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-hiddencam',
            'title' => 'HiddenCam',
            'content' => 'HiddenCam стоит 500 ₽ за 1 месяц.',
            'keywords' => ['hiddencam', 'hidden'],
        ]);

        $message = (new AiKnowledgeService())->buildContextMessage('Цена HiddenCam?');

        $this->assertNotNull($message);
        $this->assertSame('system', $message['role']);
        $this->assertStringContainsString('HiddenCam', $message['content']);
        $this->assertStringContainsString('500 ₽', $message['content']);
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'Цена BroSpace: 500 ₽.',
            'keywords' => ['brospace'],
        ]);

        $message = (new AiKnowledgeService())->buildContextMessage('Здравствуйте');

        $this->assertNull($message);
    }

    public function test_ignores_inactive_items(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-archive',
            'title' => 'Archive',
            'content' => 'Старый продукт.',
            'keywords' => ['archive'],
            'is_active' => false,
        ]);

        $items = (new AiKnowledgeService())->findRelevant('archive');

        $this->assertCount(0, $items);
    }

    public function test_prefers_strong_match_over_content_only_match(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'BroSpace стоит 500 ₽.',
            'keywords' => ['brospace'],
        ]);

        AiKnowledgeItem::create([
            'slug' => 'product-platinum',
            'title' => 'Platinum',
            'content' => 'В Platinum входит BroSpace.',
            'keywords' => ['platinum'],
        ]);

        $items = (new AiKnowledgeService())->findRelevant('Сколько стоит BroSpace?');

        $this->assertCount(1, $items);
        $this->assertSame('product-brospace', $items->first()->slug);
    }
}
