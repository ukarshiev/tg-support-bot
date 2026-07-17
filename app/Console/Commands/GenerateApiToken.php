<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExternalSource;
use App\Modules\External\Services\Source\ExternalSourceTokensService;
use App\Services\Webhook\OutboundWebhookException;
use App\Services\Webhook\OutboundWebhookUrlPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GenerateApiToken extends Command
{
    protected $signature = 'app:generate-token {source} {hook_url}';

    protected $description = 'Create or rotate an external API token and reveal the new value once';

    public function __construct(
        private readonly ExternalSourceTokensService $tokens,
        private readonly OutboundWebhookUrlPolicy $urlPolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourceName = (string) $this->argument('source');
        $hookUrl = (string) $this->argument('hook_url');
        $validator = Validator::make(compact('sourceName', 'hookUrl'), [
            'sourceName' => ['required', 'string', 'min:3', 'max:100'],
            'hookUrl' => ['required', 'url'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $this->urlPolicy->validate($hookUrl);

            [$source, $rawToken] = DB::transaction(function () use ($sourceName, $hookUrl): array {
                $source = ExternalSource::firstOrCreate(
                    ['name' => $sourceName],
                    ['webhook_url' => $hookUrl],
                );
                $source->update(['webhook_url' => $hookUrl]);

                return [$source, $this->tokens->setAccessToken($source->id)];
            });

            $this->warn('Сохраните токен сейчас: повторно он не показывается.');
            $this->line($source->name . ': ' . $rawToken);

            return self::SUCCESS;
        } catch (OutboundWebhookException $e) {
            $this->error('Webhook URL rejected: ' . $e->reason);

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Не удалось создать токен.');

            return self::FAILURE;
        }
    }
}
