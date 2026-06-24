<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Services\Settings\SettingsService;

class AiSystemPromptLoader
{
    /** Settings key under which the prompt is stored. */
    public const SETTING_KEY = 'ai.system_prompt';

    private ?string $cached = null;

    /**
     * The system prompt for AI requests.
     *
     * Stored only in the DB (`ai.system_prompt` via SettingsService) and used
     * verbatim — no templating / variable substitution. Returns '' when unset.
     * Memoized for the object's lifetime.
     *
     * @return string Prompt text
     */
    public function render(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $stored = app(SettingsService::class)->get(self::SETTING_KEY);

        return $this->cached = is_string($stored) ? $stored : '';
    }
}
