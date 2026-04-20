<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Ping every Anthropic model alias we care about to see which one the
 * current ANTHROPIC_API_KEY is actually allowed to call. Runs a tiny
 * "say ok" prompt so the cost is negligible.
 *
 *   php artisan model:probe
 */
class ModelProbe extends Command
{
    protected $signature   = 'model:probe';
    protected $description = 'Check which Claude model aliases respond for the current API key';

    private const CANDIDATES = [
        // Default tier
        'claude-sonnet-4-6',
        'claude-sonnet-4-5',
        // Deep reasoning tier
        'claude-opus-4-7',
        'claude-opus-4-5',
        'claude-opus-4-1',
        // Fast tier
        'claude-haiku-4-6',
        'claude-haiku-4-5',
        // Computer Use
        'claude-3-5-sonnet-20241022',
    ];

    public function handle(): int
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            $this->error('❌ ANTHROPIC_API_KEY is empty');
            return self::FAILURE;
        }

        $this->line('');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' Anthropic model probe');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' key prefix: ' . substr($key, 0, 12) . '…');
        $this->line(' configured defaults:');
        $this->line('   · model        = ' . config('services.anthropic.model'));
        $this->line('   · model_opus   = ' . config('services.anthropic.model_opus'));
        $this->line('   · model_haiku  = ' . config('services.anthropic.model_haiku'));
        $this->line('───────────────────────────────────────────────');

        $client = new Client(['base_uri' => 'https://api.anthropic.com', 'timeout' => 15]);
        $works  = [];
        $fails  = [];

        foreach (self::CANDIDATES as $model) {
            $t   = microtime(true);
            try {
                $res = $client->post('/v1/messages', [
                    'headers' => [
                        'x-api-key'         => $key,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ],
                    'json' => [
                        'model'      => $model,
                        'max_tokens' => 10,
                        'messages'   => [['role' => 'user', 'content' => 'say ok']],
                    ],
                    'http_errors' => false,
                ]);
                $ms   = round((microtime(true) - $t) * 1000);
                $code = $res->getStatusCode();
                $body = $res->getBody()->getContents();

                if ($code === 200) {
                    $this->info(sprintf('  ✅ %-35s  %4d ms', $model, $ms));
                    $works[] = $model;
                } else {
                    $data = json_decode($body, true);
                    $err  = $data['error']['message'] ?? substr($body, 0, 80);
                    $this->error(sprintf('  ❌ %-35s  %s (%s)', $model, $code, $err));
                    $fails[] = $model;
                }
            } catch (\Throwable $e) {
                $this->error(sprintf('  ❌ %-35s  %s', $model, $e->getMessage()));
                $fails[] = $model;
            }
        }

        $this->line('');
        $this->line('───────────────────────────────────────────────');
        $this->info(' Working: ' . implode(', ', $works));
        if ($fails) $this->warn(' Failing: ' . implode(', ', $fails));
        $this->line('');
        $this->line(' → Set ANTHROPIC_MODEL_OPUS in your .env to the fastest');
        $this->line('   working Opus from the list above, then run:');
        $this->line('     php artisan config:clear');
        $this->line('');

        return self::SUCCESS;
    }
}
