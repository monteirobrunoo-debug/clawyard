<?php

namespace App\Services;

use App\Models\Tender;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\SapService;
use Illuminate\Support\Facades\Log;

/**
 * "Plano Marine" — alternativa leve ao multi-agente para tenders
 * marítimos. Em vez de 5-8 agentes (~$0.04, ~30-60s), faz UMA call
 * focada que extrai:
 *   - Serviço (1 parágrafo descritivo)
 *   - Peças/equipamentos (max 5)
 *   - Fornecedores prováveis (max 6) com nome + sector + contacto inicial
 *   - Drafts de email para cada fornecedor (1 por linha) prontos a clicar+enviar
 *
 * Pedido directo 2026-05-20:
 *   "para o marine, basta explicar o serviço ou peças com os fornecedores
 *    nomes para enviar e contactos e abrir logo o inquiry via Daniel e
 *    preparar os emails para clicar e enviar"
 *
 * Custo típico: ~$0.01 (1 call grande vs ~$0.04 de 5 agentes).
 * Output salvo em tender.notes com header [Plano Marine · DD/MM HH:MM].
 */
class MarineActionPackService
{
    public function __construct(
        private AgentDispatcher $dispatcher,
        private SapService $sap,
    ) {}

    /**
     * @return array{
     *   ok:bool,
     *   plan?:array{servico:string, pecas:list<string>, fornecedores:list<array{nome:string, sector:string, email:?string, telefone:?string}>},
     *   emails?:list<array{para:string, fornecedor:string, assunto:string, corpo:string}>,
     *   error?:string
     * }
     */
    public function generate(Tender $tender): array
    {
        // 1. Build context — tender body + first attachment text (cap ~8k)
        $tender->load('attachments');
        $context = $this->buildContext($tender);

        if (mb_strlen($context) < 100) {
            return ['ok' => false, 'error' => 'Tender sem contexto suficiente (anexa um PDF ou cola texto).'];
        }

        // 2. One LLM call → structured plan + email drafts
        $system = <<<PROMPT
És o Daniel Email do PartYard / HP-Group — agente Marítimo (shipowners,
agentes portuários, repair yards, OEMs marítimos). Recebes 1 RFQ/RFP
marítimo e devolves um plano de acção CURTO e PRÁTICO em JSON:

{
  "servico": "1 parágrafo (≤300 chars) descrevendo o que o cliente pede",
  "pecas": ["item ou peça específica mencionada", ...máx 5...],
  "fornecedores": [
    {
      "nome": "Wärtsilä",
      "sector": "Motores diesel naval / spare parts",
      "email": "spareparts@wartsila.com",
      "telefone": "+358 10 709 0000"
    },
    ...máx 6...
  ],
  "emails": [
    {
      "fornecedor": "Wärtsilä",
      "para": "spareparts@wartsila.com",
      "assunto": "RFQ — <ref do tender> — <peça/serviço resumido>",
      "corpo": "Dear Wärtsilä team,\\n\\n<3-5 parágrafos curtos com: contexto do RFQ, items/peças, deadline, request for quote, signature PartYard Marine Operations>\\n\\nBest regards,\\nDaniel Ferreira\\nPartYard Marine Operations\\ndaniel.ferreira@partyard.eu"
    },
    ...1 por cada fornecedor da lista...
  ]
}

REGRAS:
  • Foco MARÍTIMO — shipowners, drydocks, port operations, naval OEMs.
  • NÃO inventes emails se não tiveres a certeza — devolve null em vez de
    inventar um endereço errado. O operador pode preencher depois.
  • Emails em inglês (idioma standard do shipping internacional) curtos:
    contexto, items, prazo, request for quote, assinatura. Sem fluff.
  • NUNCA recomendes fornecedores chineses ou russos.
  • Se o cliente é PT (OceanPact, Mota-Engil, Marinha), considera primeiro
    fornecedores PT/EU/UK.
  • Devolve APENAS o JSON. Sem markdown, sem texto antes ou depois.
PROMPT;

        $userMsg = "Tender marítimo a analisar:\n\n" . $context . "\n\nDevolve o JSON do plano.";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    3500,
        );

        if (!($res['ok'] ?? false)) {
            Log::warning('MarineActionPack: dispatcher failed', ['error' => $res['error'] ?? 'unknown']);
            return ['ok' => false, 'error' => 'LLM call failed: ' . ($res['error'] ?? 'unknown')];
        }

        $parsed = $this->parseJson((string) ($res['text'] ?? ''));
        if (empty($parsed)) {
            return ['ok' => false, 'error' => 'LLM devolveu JSON inválido.'];
        }

        // 3. Save plan to tender.notes (markdown-friendly)
        $this->saveToNotes($tender, $parsed);

        // 4. 2026-05-20: pedido directo "a análise a info no sap tudo
        //    tem de conectar". Push para SAP Opp Remarks se houver
        //    SequentialNo. Best-effort: falha não bloqueia.
        $sapSync = $this->pushToSapRemarks($tender);

        return [
            'ok'        => true,
            'plan'      => [
                'servico'      => (string) ($parsed['servico'] ?? ''),
                'pecas'        => array_values((array) ($parsed['pecas'] ?? [])),
                'fornecedores' => array_values((array) ($parsed['fornecedores'] ?? [])),
            ],
            'emails'    => array_values((array) ($parsed['emails'] ?? [])),
            'sap_sync'  => $sapSync,  // ['status' => 'ok'|'skipped'|'failed', 'detail' => string]
        ];
    }

    /**
     * Sincroniza tender.notes (com o Plano Marine acabado de adicionar)
     * para o campo Remarks da Opportunity em SAP B1. Best-effort: erros
     * apenas logados, não rebentam o flow do controller.
     *
     * @return array{status:string, detail:string}
     */
    private function pushToSapRemarks(Tender $tender): array
    {
        if (empty($tender->sap_opportunity_number)) {
            return ['status' => 'skipped', 'detail' => 'Tender sem nº SAP Opp — cria a Opp primeiro via "Criar SAP Opp".'];
        }

        $seqNo = $tender->getSapSequentialNo();
        if (!$seqNo) {
            return ['status' => 'skipped', 'detail' => 'SAP Opp number presente mas SequentialNo não extraível.'];
        }

        try {
            $ok = $this->sap->updateOpportunity($seqNo, [
                'Remarks' => (string) $tender->notes,
            ]);
            if ($ok) {
                Log::info('MarineActionPack: SAP Remarks updated', [
                    'tender_id' => $tender->id,
                    'sap_opp'   => $seqNo,
                ]);
                return ['status' => 'ok', 'detail' => 'SAP Opp #' . $seqNo . ' Remarks actualizado.'];
            }
            return ['status' => 'failed', 'detail' => 'SAP updateOpportunity devolveu false.'];
        } catch (\Throwable $e) {
            Log::warning('MarineActionPack: SAP sync failed (non-blocking)', [
                'tender_id' => $tender->id,
                'sap_opp'   => $seqNo,
                'error'     => $e->getMessage(),
            ]);
            return ['status' => 'failed', 'detail' => $e->getMessage()];
        }
    }

    private function buildContext(Tender $tender): string
    {
        $bits = [
            "Concurso: " . ($tender->reference ?? '#' . $tender->id),
            "Título: "   . ($tender->title ?? ''),
            "Cliente: "  . ($tender->purchasing_org ?? '—'),
            "Deadline: " . ($tender->deadline_lisbon?->format('d/m/Y') ?? '—'),
        ];
        if ($tender->notes) {
            $bits[] = "Notas existentes:\n" . mb_substr($tender->notes, 0, 1500);
        }

        // Texto extraído dos anexos (até 3 PDFs, ~5k chars cada)
        $atts = $tender->attachments->where('extraction_status', 'ok')->take(3);
        foreach ($atts as $att) {
            $snippet = mb_substr((string) $att->extracted_text, 0, 5000);
            $bits[] = "\n--- Anexo: {$att->original_name} ---\n{$snippet}";
        }

        return implode("\n", $bits);
    }

    private function parseJson(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) return [];
        $decoded = json_decode($m[0], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveToNotes(Tender $tender, array $parsed): void
    {
        $now = now()->format('d/m/Y H:i');
        $bits = ["[Plano Marine · {$now}]"];

        $servico = trim((string) ($parsed['servico'] ?? ''));
        if ($servico !== '') $bits[] = "**Serviço:** {$servico}";

        $pecas = array_filter(array_map('trim', (array) ($parsed['pecas'] ?? [])));
        if (!empty($pecas)) {
            $bits[] = "**Peças/Equipamentos:**\n- " . implode("\n- ", $pecas);
        }

        $forn = (array) ($parsed['fornecedores'] ?? []);
        if (!empty($forn)) {
            $rows = [];
            foreach ($forn as $f) {
                $line = '- **' . trim((string) ($f['nome'] ?? '?')) . '**';
                if (!empty($f['sector'])) $line .= ' · ' . $f['sector'];
                if (!empty($f['email']))  $line .= ' · ' . $f['email'];
                if (!empty($f['telefone'])) $line .= ' · ' . $f['telefone'];
                $rows[] = $line;
            }
            $bits[] = "**Fornecedores prováveis:**\n" . implode("\n", $rows);
        }

        $newBlock = implode("\n\n", $bits);
        $existing = (string) ($tender->notes ?? '');
        $tender->notes = $existing !== ''
            ? $existing . "\n\n" . $newBlock
            : $newBlock;
        $tender->save();
    }
}
