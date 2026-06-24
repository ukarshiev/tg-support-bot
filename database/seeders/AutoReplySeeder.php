<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AutoReply;
use Illuminate\Database\Seeder;

class AutoReplySeeder extends Seeder
{
    /**
     * Seed demo auto-reply rules (only when the table is empty).
     */
    public function run(): void
    {
        if (AutoReply::query()->exists()) {
            return;
        }

        $rules = [
            [
                'trigger' => 'Привет',
                'response' => 'Здравствуйте! Добро пожаловать в нашу поддержку. Чем могу помочь?',
                'enabled' => true,
            ],
            [
                'trigger' => 'Цена',
                'response' => 'Актуальные цены вы можете найти на нашем сайте в разделе «Тарифы».',
                'enabled' => true,
            ],
            [
                'trigger' => 'Доставка',
                'response' => 'Доставка осуществляется в течение 3-5 рабочих дней по всей России.',
                'enabled' => true,
            ],
            [
                'trigger' => 'Контакты',
                'response' => 'Наши контакты: email support@example.com, телефон +7 (999) 123-45-67.',
                'enabled' => false,
            ],
        ];

        foreach ($rules as $rule) {
            AutoReply::create($rule);
        }
    }
}
