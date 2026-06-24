<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExternalSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExternalSourceAccessTokens>
 */
class ExternalSourceAccessTokensFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_source_id' => ExternalSource::factory(),
            'token' => Str::random(64),
            'active' => true,
        ];
    }
}
