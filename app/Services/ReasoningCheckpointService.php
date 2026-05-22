<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReasoningCheckpointService — post-validation de outputs de agentes.
 *
 * Base teórica: Bornet et al. 2025 ("Agentic Artificial Intelligence"),
 * Cap 6 "Reasoning Checkpoints". Citação: "predetermined points where
 * critical decisions require multiple levels of validation".
 *
 * Padrão: o agente produz output → este service corre validação leve
 * com Haiku 4.5 (~$0.0005 per call) → devolve issues array. O caller
 * decide: append warning ao output, bloquear, ou pedir confirmação.
 *
 * 3 tipos de validação disponíveis:
 *   1. validateEmailDraft($input, $output) — para drafts de emails a
 *      clientes/fornecedores (Daniel, Marco). Detecta:
 *        - Preços ou prazos inventados (não no input)
 *        - Promessas de entrega sem base
 *        - Garantias sem base contratual
 *        - Tom inadequado (defesa, militar)
 *
 *   2. validateSapWrite($input, $output, $sapResponse) — para
 *      operações SAP B1 (Marta). Detecta:
 *        - SequentialNo claims sem match no SAP response real
 *        - "Created"/"updated" claims sem evidência
 *        - SlpCode/CardCode mismatches
 *
 *   3. validateGeneral($input, $output) — sanity check genérico.
 *      Detecta números, datas, contactos que não estão no input.
 *
 * Cache 5 min por (input_hash, output_hash) — re-validação de mesmo
 * par é grátis. Útil quando o user faz follow-up sem mudar contexto.
 *
 * Custo: ~$0.0005 por validação fresca, $0 cached. Para 100 chats/dia
 * = ~$0.05/dia, dentro do budget.
 */
class ReasoningCheckpointService
{
    public function __construct(private AgentDispatcher $dispatcher) {}

    /**
     * Validação leve para drafts de emails.
     * @return array{ok:bool, issues:array<string>, severity:'pass'|'warn'|'block'}
     */
    public function validateEmailDraft(string $input, string $output): array
    {
        if (mb_strlen($output) < 50) return $this->pass();  // muito curto para validar

        $prompt = <<<PROMPT
És um validador de emails B2B do PartYard/HP-Group. Recebes:
  - INPUT: contexto + pedido do user (o que ele queria)
  - OUTPUT: draft de email que outro agente produziu

Tarefa: detectar ALUCINAÇÕES ou risco antes do email sair.

PROCURA por estes problemas:
  1. PREÇOS específicos no email que NÃO aparecem no INPUT
  2. PRAZOS/DATAS de entrega sem base no INPUT
  3. PROMESSAS contratuais ou garantias sem evidência
  4. NOMES de contactos ou empresas que não estão no INPUT
  5. NSNs ou Part Numbers inventados (não no INPUT)
  6. Linguagem inadequada para defesa/militar (se contexto for esse)

Devolve APENAS este JSON:
{
  "ok": true|false,
  "issues": ["descrição curta cada um", ...],
  "severity": "pass" | "warn" | "block"
}

severity:
  - "pass": email pode sair sem alterações
  - "warn": tem riscos mas pode sair com aviso ao user
  - "block": NÃO deve sair — invenções graves
PROMPT;

        return $this->runValidation($prompt, $input, $output, 'email');
    }

    /**
     * Validação de operação SAP B1 — verifica que o output do agente
     * corresponde ao $sapResponse real (não inventou SequentialNo).
     *
     * @return array{ok:bool, issues:array<string>, severity:'pass'|'warn'|'block'}
     */
    public function validateSapWrite(string $input, string $output, ?string $sapResponse = null): array
    {
        $prompt = <<<PROMPT
És um validador de operações SAP B1 da Marta CRM (PartYard).
Recebes:
  - INPUT: contexto + intent do user
  - OUTPUT: confirmação que a Marta escreveu ao user
  - SAP_RESPONSE: resposta REAL do SAP B1 (pode ser null/vazia se a chamada falhou)

Tarefa: detectar quando a Marta diz que algo foi criado/actualizado mas o SAP_RESPONSE não confirma.

PROCURA por:
  1. "Oportunidade #N criada" no OUTPUT mas SequentialNo N não aparece no SAP_RESPONSE
  2. SlpCode/CardCode mencionados no OUTPUT que não estão no SAP_RESPONSE
  3. "Activity registada" sem confirmação no SAP_RESPONSE
  4. Qualquer claim de operação irreversível (delete, update) sem evidência

severity:
  - "block": output afirma sucesso sem evidência (alucinação grave)
  - "warn": output ambíguo, pode confundir user
  - "pass": claims do output match SAP_RESPONSE

Devolve APENAS JSON: {ok, issues, severity}
PROMPT;

        $combined = $input . "\n\n=== SAP RESPONSE ===\n" . ($sapResponse ?? '(vazio)');
        return $this->runValidation($prompt, $combined, $output, 'sap');
    }

    /**
     * Validação genérica para chats normais — sanity check leve.
     * @return array{ok:bool, issues:array<string>, severity:'pass'|'warn'|'block'}
     */
    public function validateGeneral(string $input, string $output): array
    {
        if (mb_strlen($output) < 100) return $this->pass();

        $prompt = <<<PROMPT
Sanity check sobre uma resposta de agente PartYard/HP-Group.
INPUT: pergunta + contexto do user. OUTPUT: resposta do agente.

Procura SÓ alucinações concretas:
  - Preços específicos sem fonte no INPUT
  - Dados de contacto (email, telefone) que não estão no INPUT
  - Refs/IDs (NSN, SAP code, tender ID) inventados
  - Citações a páginas/livros que não fazem sentido

Não te preocupes com opiniões/tom — só factos verificáveis.

severity: "pass" | "warn" | "block"
Devolve APENAS JSON: {ok, issues, severity}
PROMPT;

        return $this->runValidation($prompt, $input, $output, 'general');
    }

    /** Helper: execução comum com cache + Haiku call. */
    private function runValidation(string $systemPrompt, string $input, string $output, string $kind): array
    {
        $cacheKey = 'reasoning_check:v1:' . $kind . ':' . md5($input . '|' . $output);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        $userMsg = "=== INPUT ===\n" . mb_substr($input, 0, 3000)
                 . "\n\n=== OUTPUT ===\n" . mb_substr($output, 0, 3000)
                 . "\n\nValida e devolve JSON.";

        $haikuModel = (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001');

        try {
            $res = $this->dispatcher->dispatch(
                systemPrompt: $systemPrompt,
                userMessage:  $userMsg,
                maxTokens:    400,
                model:        $haikuModel,
            );

            if (!($res['ok'] ?? false)) {
                Log::info('ReasoningCheckpoint: dispatch failed — fail open', [
                    'kind' => $kind, 'error' => $res['error'] ?? '?'
                ]);
                return $this->pass();  // fail open — não bloquear UX por bug do checker
            }

            $raw = trim((string) ($res['text'] ?? ''));
            $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
            if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) {
                Log::info('ReasoningCheckpoint: no JSON returned', ['kind' => $kind]);
                return $this->pass();
            }
            $decoded = json_decode($m[0], true);
            if (!is_array($decoded)) return $this->pass();

            $result = [
                'ok'       => (bool) ($decoded['ok'] ?? true),
                'issues'   => array_slice(array_filter(
                    array_map(fn ($s) => mb_substr(trim((string) $s), 0, 200), (array) ($decoded['issues'] ?? []))
                ), 0, 5),
                'severity' => in_array($decoded['severity'] ?? 'pass', ['pass','warn','block'], true)
                                ? $decoded['severity'] : 'pass',
            ];

            // Cache 5 min — mesmo par input/output não revalida.
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            if ($result['severity'] !== 'pass') {
                Log::info('ReasoningCheckpoint: issues detected', [
                    'kind' => $kind, 'severity' => $result['severity'],
                    'n_issues' => count($result['issues']),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('ReasoningCheckpoint: exception — fail open: ' . $e->getMessage());
            return $this->pass();
        }
    }

    /** "Pass" default — devolvido em early-exit ou em fail-open. */
    private function pass(): array
    {
        return ['ok' => true, 'issues' => [], 'severity' => 'pass'];
    }

    /**
     * Helper: formata issues como bloco markdown para append à resposta
     * do agente. Usado quando severity = "warn" — user vê aviso mas a
     * resposta sai. Quando "block", o caller deve replace o output.
     */
    public function formatIssuesBlock(array $result): string
    {
        if ($result['severity'] === 'pass') return '';
        $emoji = $result['severity'] === 'block' ? '🛑' : '⚠️';
        $title = $result['severity'] === 'block' ? 'Validação BLOQUEOU resposta' : 'Validação detectou riscos';

        $lines = ["\n\n---", "{$emoji} **{$title}** (auto-check Haiku)"];
        foreach ($result['issues'] as $issue) {
            $lines[] = "  • " . $issue;
        }
        $lines[] = "_Revê antes de actuar com base nesta resposta._";
        return implode("\n", $lines);
    }
}
