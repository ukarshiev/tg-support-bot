<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExternalSourceAccessTokens;
use Illuminate\Console\Command;

class FinalizeExternalTokenHashes extends Command
{
    protected $signature = 'external-tokens:finalize {--force : Clear plaintext tokens without confirmation}';

    protected $description = 'Clear legacy plaintext after every external token has a SHA-256 hash';

    public function handle(): int
    {
        if (ExternalSourceAccessTokens::whereNull('token_hash')->exists()) {
            $this->error('Cannot finalize: at least one token has no hash.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Clear every legacy plaintext token?')) {
            return self::FAILURE;
        }

        $count = ExternalSourceAccessTokens::whereNotNull('token')->update(['token' => null]);
        $this->info("Cleared {$count} plaintext token(s).");

        return self::SUCCESS;
    }
}
