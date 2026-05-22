<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Services\MultiAgentDebateService;
use Illuminate\Console\Command;

/**
 * debate:tender — corre debate multi-agente sobre um tender.
 *
 * Uso:
 *   php artisan debate:tender 309
 *   php artisan debate:tender 309 --topic="risco geopolítico desta proposta"
 *   php artisan debate:tender 309 --agents=mildef,sales,engineer
 *
 * Custo: ~$0.15-0.30 (3 agentes × 2 rounds × Sonnet + 1 Haiku synthesis).
 * Persiste em multi_agent_debates table — pode ser revisto depois.
 */
class DebateTenderCommand extends Command
{
    protected $signature = 'debate:tender
                            {tender_id : ID do tender a debater}
                            {--topic= : Pergunta concreta (default = sumário do tender)}
                            {--agents= : Lista CSV de agent_keys (override default)}';

    protected $description = 'Corre debate multi-agente sobre um tender (3 rounds)';

    public function handle(MultiAgentDebateService $svc): int
    {
        $tender = Tender::find((int) $this->argument('tender_id'));
        if (!$tender) {
            $this->error('Tender não encontrado: ' . $this->argument('tender_id'));
            return self::FAILURE;
        }

        if ($tender->is_confidential) {
            $this->error('Tender confidencial — debate não permitido.');
            return self::FAILURE;
        }

        $agents = null;
        if ($csv = $this->option('agents')) {
            $agents = array_values(array_filter(array_map('trim', explode(',', $csv))));
        }

        $this->info("🎙️  Iniciando debate para Tender #{$tender->id}");
        $this->line("   Título: " . mb_strimwidth((string) $tender->title, 0, 80, '…'));
        $this->line('');

        $t0 = microtime(true);
        try {
            $debate = $svc->debate(
                tender: $tender,
                topic: $this->option('topic'),
                agents: $agents,
            );
        } catch (\Throwable $e) {
            $this->error('Debate falhou: ' . $e->getMessage());
            return self::FAILURE;
        }
        $dt = round(microtime(true) - $t0, 1);

        $this->line('');
        $this->info("✓ Debate #{$debate->id} ({$dt}s, \${$debate->cost_usd})");
        $this->line('');

        $this->info('--- SYNTHESIS ---');
        $this->line($debate->synthesis ?? '(vazio)');
        $this->line('');

        if (!empty($debate->disagreements)) {
            $this->warn('--- DISAGREEMENTS ---');
            foreach ((array) $debate->disagreements as $d) {
                $this->line('• ' . ($d['topic'] ?? '?'));
                foreach ((array) ($d['positions'] ?? []) as $agent => $pos) {
                    $this->line("    {$agent}: {$pos}");
                }
            }
            $this->line('');
        }

        $confColor = ($debate->confidence_pct ?? 0) >= 80 ? 'info'
                   : (($debate->confidence_pct ?? 0) >= 50 ? 'warn' : 'error');
        $this->{$confColor}("Confidence: {$debate->confidence_pct}%");

        return self::SUCCESS;
    }
}
