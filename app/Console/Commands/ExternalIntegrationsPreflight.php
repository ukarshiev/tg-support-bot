<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ExternalIntegrationsPreflight extends Command
{
    protected $signature = 'security:external-preflight';

    protected $description = 'Verify that external integrations are ready for the hardened protocol';

    public function handle(): int
    {
        $legacyWidgetKeys = Schema::hasColumn('external_sources', 'public_key')
            ? ExternalSource::whereNotNull('public_key')->where('public_key', '!=', '')->count()
            : 0;
        $tokensWithoutHash = ExternalSourceAccessTokens::whereNull('token_hash')->count();

        if ($legacyWidgetKeys > 0 || $tokensWithoutHash > 0) {
            $this->error("Preflight failed: widget_keys={$legacyWidgetKeys}, tokens_without_hash={$tokensWithoutHash}");

            return self::FAILURE;
        }

        $this->info('External integrations preflight passed.');

        return self::SUCCESS;
    }
}
