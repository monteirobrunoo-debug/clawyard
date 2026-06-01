<?php

namespace App\Services;

use App\Models\ReviewChainRun;
use App\Models\Tender;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * ReviewChainService — pipeline sequencial de revisão por comité de agentes.
 *
 * Fluxo (gate logic — para ao primeiro rejeito):
 *   1. 💼 Marco Sales        — viabilidade comercial & proposta
 *   2. 💰 Dr. Luís Finance   — margem e solidez financeira
 *   3. 🔐 ARIA Security      — confidencialidade & segurança
 *   4. 🎖️  Cor. Rodrigues    — conformidade defesa (Cat. ML13/ML14)
 *
 * Cada revisor recebe o documento original + notas de todos os
 * revisores anteriores. Devolve JSON estruturado com verdict + flags.
 * Se approved=false → chain para; o documento não avança.
 *
 * Auditável: cada corrida fica em review_chain_runs com steps detalhados.
 */
class ReviewChainService
{
    /** Revisores em ordem — cada um vê o que os anteriores disseram. */
    private const REVIEWERS = [
        [
            'agent_key' => 'sales',
            'name'      => 'Marco — Sales',
            'emoji'     => '💼',
            'role'      => 'Viabilidade comercial & proposta',
            'focus'     => 'preços competitivos vs mercado, margem adequada (>15%), promessas de entrega realistas, linguagem da proposta clara e profissional',
        ],
        [
            'agent_key' => 'finance',
            'name'      => 'Dr. Luís — Financeiro',
            'emoji'     => '💰',
            'role'      => 'Validação financeira & margem',
            'focus'     => 'margem mínima 15% antes de impostos, custos de transporte e alfândega incluídos, termos de pagamento adequados, risco cambial se moeda estrangeira',
        ],
        [
            'agent_key' => 'aria',
            'name'      => 'ARIA — Segurança',
            'emoji'     => '🔐',
            'role'      => 'Confidencialidade & segurança de informação',
            'focus'     => 'dados confidenciais de clientes expostos, preços de custo internos não mascarados, informação proprietária HP/PartYard, classificação de informação sensível',
        ],
        [
            'agent_key' => 'mildef',
            'name'      => 'Cor. Rodrigues — Defesa',
            'emoji'     => '🎖️',
            'role'      => 'Conformidade defesa (ML13/ML14)',
            'focus'     => 'categorias restritas ML13 (material militar) e ML14 (equipamento especializado), fornecedores chineses/russos, requisitos NATO/ITAR/EAR, licenças de exportação necessárias',
        ],
    ];

    public function __construct(private AgentDispatcher $dispatcher) {}

    /**
     * Executa a cadeia de revisão para um tender. Persiste o resultado
     * no registo $run passado (já criado pelo controller/job).
     */
    public function review(ReviewChainRun $run, Tender $tender): ReviewChainRun
    {
        $run->update(['status' => ReviewChainRun::STATUS_RUNNING, 'started_at' => now()]);

        $document = $this->buildDocument($tender);
        $steps      = [];
        $prevNotes  = [];
        $costTotal  = 0.0;
        $overallApproved = true;
        $stoppedAt  = null;

        foreach (self::REVIEWERS as $idx => $reviewer) {
            Log::info('ReviewChain: step ' . ($idx + 1), [
                'run'   => $run->id,
                'agent' => $reviewer['agent_key'],
            ]);

            $result = $this->runReviewer($reviewer, $document, $prevNotes);
            $costTotal += $result['cost_usd'];

            $steps[] = array_merge(
                array_intersect_key($reviewer, array_flip(['agent_key', 'name', 'emoji', 'role'])),
                $result,
            );

            if (!$result['approved']) {
                $overallApproved = false;
                $stoppedAt = $idx;
                break; // gate: chain para ao primeiro rejeito
            }

            $prevNotes[] = "[{$reviewer['emoji']} {$reviewer['role']}] " . $result['verdict']
                . ($result['notes'] ? " — {$result['notes']}" : '');
        }

        $run->update([
            'status'          => ReviewChainRun::STATUS_DONE,
            'steps'           => $steps,
            'overall_approved'=> $overallApproved,
            'stopped_at_step' => $stoppedAt,
            'cost_usd'        => round($costTotal, 4),
            'finished_at'     => now(),
        ]);

        return $run->fresh();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function runReviewer(array $reviewer, string $document, array $prevNotes): array
    {
        $notesBlock = empty($prevNotes)
            ? 'Primeiro revisor — sem notas anteriores.'
            : implode("\n", $prevNotes);

        $system = <<<SYSTEM
És {$reviewer['name']}, revisor especializado numa cadeia de qualidade da PartYard / HP Group.
Papel nesta revisão: {$reviewer['role']}.
Foco específico: {$reviewer['focus']}.
Sê criterioso mas justo. Rejeita APENAS se houver um problema sério e claro.
SYSTEM;

        $user = <<<USER
DOCUMENTO A REVER:
---
{$document}
---

NOTAS DOS REVISORES ANTERIORES:
{$notesBlock}

Revisa o documento acima exclusivamente no teu papel de "{$reviewer['role']}".
Responde APENAS com JSON válido (sem markdown, sem texto antes ou depois):
{
  "approved": true,
  "verdict": "Aprovado",
  "notes": "Observação curta e factual.",
  "flags": [],
  "confidence": 85
}

Regras:
• approved: true se aprovado ou com reservas menores; false se rejeitas por problema sério.
• verdict: "Aprovado" | "Aprovado com reservas" | "Rejeitado"
• notes: máx 200 caracteres, observação factual.
• flags: lista de strings com problemas críticos (vazia se nenhum).
• confidence: 0–100 (tua confiança na revisão, considerando info disponível).
USER;

        $start  = microtime(true);
        $result = $this->dispatcher->dispatch($system, $user, maxTokens: 512, model: 'claude-haiku-4-6');
        $ms     = (int) ((microtime(true) - $start) * 1000);

        if (!($result['ok'] ?? false)) {
            Log::warning('ReviewChain: dispatcher failed', ['error' => $result['error'] ?? '?']);
            return [
                'approved'   => false,
                'verdict'    => 'Erro de sistema',
                'notes'      => 'Falha ao contactar o revisor: ' . ($result['error'] ?? 'desconhecido'),
                'flags'      => ['dispatch_error'],
                'confidence' => 0,
                'cost_usd'   => 0.0,
                'ms'         => $ms,
            ];
        }

        $parsed = $this->parseReviewerJson($result['text'] ?? '');

        return array_merge($parsed, [
            'cost_usd' => (float) ($result['cost_usd'] ?? 0.0),
            'ms'       => $ms,
        ]);
    }

    private function parseReviewerJson(string $text): array
    {
        $defaults = [
            'approved'   => false,
            'verdict'    => 'Erro de parsing',
            'notes'      => 'Resposta não parseável.',
            'flags'      => ['parse_error'],
            'confidence' => 0,
        ];

        $text = trim($text);
        // Remove markdown fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);
        if (!is_array($data)) {
            // Try to extract the first JSON object
            if (preg_match('/\{[^{}]+\}/s', $text, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) return $defaults;

        return [
            'approved'   => (bool) ($data['approved'] ?? false),
            'verdict'    => mb_substr((string) ($data['verdict'] ?? 'Sem veredicto'), 0, 80),
            'notes'      => mb_substr((string) ($data['notes'] ?? ''), 0, 300),
            'flags'      => array_slice((array) ($data['flags'] ?? []), 0, 10),
            'confidence' => max(0, min(100, (int) ($data['confidence'] ?? 50))),
        ];
    }

    private function buildDocument(Tender $tender): string
    {
        $lines = [];
        $lines[] = "TÍTULO: {$tender->title}";
        if ($tender->reference) $lines[] = "REFERÊNCIA: {$tender->reference}";
        if ($tender->type)      $lines[] = "TIPO: {$tender->type}";
        if ($tender->source)    $lines[] = "FONTE: {$tender->source}";
        if ($tender->purchasing_org) $lines[] = "ORGANIZAÇÃO COMPRADORA: {$tender->purchasing_org}";
        if ($tender->offer_value)    $lines[] = "VALOR DA PROPOSTA: {$tender->offer_value} {$tender->currency}";
        if ($tender->deadline_at)    $lines[] = "PRAZO: " . $tender->deadline_at->format('d/m/Y');
        if ($tender->notes)          $lines[] = "\nNOTAS:\n{$tender->notes}";

        $meta = $tender->raw_metadata ?? [];
        if (!empty($meta['description'])) $lines[] = "\nDESCRIÇÃO:\n" . mb_substr($meta['description'], 0, 1500);

        return implode("\n", $lines);
    }
}
