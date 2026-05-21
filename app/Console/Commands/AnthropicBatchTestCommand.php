<?php

namespace App\Console\Commands;

use App\Models\AnthropicBatch;
use App\Services\AnthropicBatchService;
use Illuminate\Console\Command;

/**
 * Smoke test do AnthropicBatchService. Submete um batch trivial
 * (3 prompts curtos), pollá-o até estar ended, descarrega results.
 *
 * Uso:
 *   php artisan anthropic:test-batch [--poll]
 *
 * --poll: em vez de submeter novo, polla o último batch ainda activo.
 */
class AnthropicBatchTestCommand extends Command
{
    protected $signature = 'anthropic:test-batch {--poll : Polla o último batch em vez de submeter novo}';
    protected $description = 'Smoke test ao Anthropic Batch API';

    public function handle(AnthropicBatchService $svc): int
    {
        if (!$svc->isAvailable()) {
            $this->error('ANTHROPIC_API_KEY não está configurado.');
            return self::FAILURE;
        }

        if ($this->option('poll')) {
            $batch = AnthropicBatch::pending()->orderByDesc('id')->first();
            if (!$batch) {
                $this->info('Sem batches pendentes para pollar.');
                return self::SUCCESS;
            }
            $this->info("A pollar batch #{$batch->id} ({$batch->batch_id})…");
            $svc->poll($batch);
            $this->info("Status: {$batch->status}");
            if ($batch->status === AnthropicBatch::STATUS_ENDED) {
                $r = $svc->collectResults($batch);
                $this->info('Results: ' . count($r));
                foreach ($r as $cid => $row) {
                    if ($row['ok']) {
                        $this->line("  ✓ {$cid}: " . mb_substr($row['text'], 0, 100));
                    } else {
                        $this->line("  ✗ {$cid}: " . $row['error']);
                    }
                }
            }
            return self::SUCCESS;
        }

        $this->info('Submetendo batch test com 3 prompts curtos…');
        $batch = $svc->submit(
            model: 'claude-haiku-4-5-20251001',
            kind:  'smoke-test',
            requests: [
                // custom_id pattern: ^[a-zA-Z0-9_-]{1,64}$ (Anthropic exige)
                ['custom_id' => 'test-1', 'system' => 'You are a calculator.', 'messages' => [['role' => 'user', 'content' => 'What is 2+2? Reply ONLY with the number.']], 'max_tokens' => 10],
                ['custom_id' => 'test-2', 'system' => 'You are a calculator.', 'messages' => [['role' => 'user', 'content' => 'What is 10*7? Reply ONLY with the number.']], 'max_tokens' => 10],
                ['custom_id' => 'test-3', 'system' => 'You are a calculator.', 'messages' => [['role' => 'user', 'content' => 'What is 100-23? Reply ONLY with the number.']], 'max_tokens' => 10],
            ],
        );

        if (!$batch || !$batch->batch_id) {
            $this->error('Submission falhou. Vê AnthropicBatch row #' . ($batch->id ?? '?'));
            return self::FAILURE;
        }

        $this->info("✓ Batch criado:");
        $this->line("   Row id     : {$batch->id}");
        $this->line("   Batch id   : {$batch->batch_id}");
        $this->line("   Status     : {$batch->status}");
        $this->line("   Requests   : {$batch->request_count}");
        $this->line('');
        $this->line('Polla com: php artisan anthropic:test-batch --poll');
        $this->line('(SLA Anthropic ≤24h, normalmente <1min para batch pequeno)');

        return self::SUCCESS;
    }
}
