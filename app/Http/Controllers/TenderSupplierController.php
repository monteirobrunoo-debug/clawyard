<?php

namespace App\Http\Controllers;

use App\Agents\EmailAgent;
use App\Models\Supplier;
use App\Models\Tender;
use App\Services\TenderSupplierSuggesterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * AJAX endpoints powering the "🤖 Sugerir fornecedores e drafts"
 * panel on /tenders/{id}.
 *
 * Two-step flow:
 *   1. POST /tenders/{tender}/suggest-suppliers
 *      → returns inferred categories + matched local suppliers + web hits
 *
 *   2. POST /tenders/{tender}/draft-supplier-emails
 *      → with the user-selected supplier_ids, asks Daniel to write one
 *        tailored email per supplier (multi-card output via SHAPE B)
 *
 * Both gated by tenders.view-all-or-self (mirrors the show() endpoint
 * — if you can't see the tender you can't draft outreach for it).
 */
class TenderSupplierController extends Controller
{
    public function suggest(Request $request, Tender $tender, TenderSupplierSuggesterService $svc): JsonResponse
    {
        $this->authorizeTender($tender);

        // Confidential tenders block ALL external augmentation —
        // no Tavily, no Claude. Only the local supplier match runs.
        // See migration 2026_04_30_000004 for the rationale.
        if ($tender->is_confidential) {
            $bundle = $svc->suggest($tender, localLimit: 3, includeWeb: false);
            return response()->json([
                'categories'    => $bundle['categories'],
                'web_query'     => null,
                'web_available' => false,
                'confidential'  => true,
                'local'         => $bundle['local']->map(fn(Supplier $s) => $this->shapeSupplier($s))->values()->all(),
                'web'           => [],
            ]);
        }

        $includeWeb = $request->boolean('include_web', true);

        $bundle = $svc->suggest($tender, localLimit: 3, includeWeb: $includeWeb);

        return response()->json([
            'categories'      => $bundle['categories'],
            'web_query'       => $bundle['query'],
            'web_available'   => $bundle['web_available'],
            'confidential'    => false,
            'local'           => $bundle['local']->map(fn(Supplier $s) => $this->shapeSupplier($s))->values()->all(),
            'web'             => $bundle['web'],
            'expert_opinions' => $bundle['expert_opinions'] ?? [],
            // 2026-05-18: quando especialistas dizem "fora do domínio H&P",
            // local fica vazio e oem_direct traz a lista de OEMs a contactar
            // directamente (Karl Storz, Medtronic, Interacoustics, …).
            'out_of_scope'    => $bundle['out_of_scope'] ?? false,
            'oem_direct'      => $bundle['oem_direct']   ?? [],
        ]);
    }

    /**
     * Shape one Supplier as a JSON row for the AJAX panel.
     * Pulled out so confidential + non-confidential paths render
     * identical structure.
     */
    private function shapeSupplier(Supplier $s): array
    {
        return [
            'id'             => $s->id,
            'name'           => $s->name,
            'slug'           => $s->slug,
            'primary_email'  => $s->primary_email,
            'iqf_score'      => $s->iqf_score !== null ? (float) $s->iqf_score : null,
            'status'         => $s->status,
            'categories'     => $s->categories ?? [],
            'subcategories'  => $s->subcategories ?? [],
            'brands'         => $s->brands ?? [],
            'has_email'      => !empty($s->primary_email),
            'last_contacted' => $s->last_contacted_at?->diffForHumans(),
            'detail_url'     => route('suppliers.show', $s),
        ];
    }

    public function draft(Request $request, Tender $tender, EmailAgent $daniel): JsonResponse
    {
        $this->authorizeTender($tender);

        // Confidential tenders cannot dispatch Daniel — he'd send the
        // tender title + supplier list to Claude. Tell the operator to
        // toggle off the flag if they really want LLM drafts.
        if ($tender->is_confidential) {
            return response()->json([
                'error'  => 'tender_confidential',
                'detail' => 'Este concurso está marcado como confidencial — drafts via LLM estão desligados. Desmarca a flag em "Confidencial" para usar o Daniel.',
            ], 403);
        }

        $data = $request->validate([
            'supplier_ids'   => ['required', 'array', 'min:1', 'max:12'],
            'supplier_ids.*' => ['integer', 'exists:suppliers,id'],
            'language'       => ['nullable', 'in:pt,en,es'],
            'note'           => ['nullable', 'string', 'max:1000'],
        ]);

        $suppliers = Supplier::whereIn('id', $data['supplier_ids'])->get();
        if ($suppliers->isEmpty()) {
            return response()->json(['error' => 'no_suppliers_found'], 422);
        }

        $language = $data['language'] ?? 'pt';
        $note     = trim((string) ($data['note'] ?? ''));

        // Build the prompt for Daniel. We give him the tender context,
        // the supplier list with names + emails + brands, and tell him
        // to use SHAPE B (one email per supplier).
        $prompt = $this->buildPrompt($tender, $suppliers, $language, $note);

        try {
            $reply = $daniel->chat($prompt);
        } catch (\Throwable $e) {
            Log::warning('Tender draft-supplier-emails failed', [
                'tender_id' => $tender->id,
                'count'     => $suppliers->count(),
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'error'  => 'agent_error',
                'detail' => $e->getMessage(),
            ], 502);
        }

        // Daniel's parser already wraps successful output in __EMAIL__ /
        // __EMAILS__ sentinels (see EmailAgent::parseEmailJson). For
        // this endpoint we want the structured payload, not the
        // sentinel wrapper.
        if (str_starts_with($reply, '__EMAILS__')) {
            $payload = json_decode(substr($reply, strlen('__EMAILS__')), true);
            return response()->json([
                'shape'  => 'multi',
                'emails' => $payload['emails'] ?? [],
                'language' => $payload['language'] ?? $language,
                'suggestions' => $payload['suggestions'] ?? [],
            ]);
        }

        if (str_starts_with($reply, '__EMAIL__')) {
            $single = json_decode(substr($reply, strlen('__EMAIL__')), true);
            return response()->json([
                'shape'  => 'single',
                'emails' => [$single],
                'language' => $single['language'] ?? $language,
            ]);
        }

        // Fallback — Daniel returned free-form text. Surface it so the
        // operator can copy/paste, even if the structure is degraded.
        return response()->json([
            'shape' => 'fallback',
            'text'  => $reply,
        ], 200);
    }

    private function buildPrompt(Tender $tender, $suppliers, string $language, string $note): string
    {
        $deadline = $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—';
        $ref      = $tender->reference ?: '—';
        $org      = $tender->purchasing_org ?: '—';

        $supLines = $suppliers->map(function (Supplier $s) {
            $bits = ["• {$s->name}"];
            if ($s->primary_email) $bits[] = "(" . $s->primary_email . ")";
            if (!empty($s->brands)) $bits[] = '— marcas: ' . implode(', ', (array) $s->brands);
            if ($s->iqf_score !== null) $bits[] = '— IQF ' . rtrim(rtrim(number_format((float) $s->iqf_score, 2), '0'), '.');
            return implode(' ', $bits);
        })->implode("\n");

        $langLine = match ($language) {
            'en' => 'Write the emails in English.',
            'es' => 'Escribe los emails en español.',
            default => 'Escreve os emails em português europeu (pt-PT).',
        };

        $noteBlock = $note !== '' ? "\n\nNotas adicionais do operador:\n{$note}" : '';

        // PDF context: the actual RFP/RFQ body usually has the spec
        // detail Daniel needs to write a credible quote-request email.
        // We feed him the first ~6KB of each attached PDF (capped via
        // promptSnippet on the model) so he can reference real
        // equipment / part numbers / quantities in each draft.
        //
        // 2026-05-18: bloco SEPARADO para o Statement of Requirements
        // se ele existir. Pedido directo do operador: "falta a info
        // que está no statement of requirements, muito importante".
        // SoR contém as specs técnicas linha-a-linha que o fornecedor
        // precisa de ver para cotar — não pode ser misturada com o
        // resto do RFP boilerplate.
        $pdfBlock = '';
        $sorBlock = '';
        try {
            $atts = $tender->attachments()->where('extraction_status', 'ok')->get();
            if ($atts->isNotEmpty()) {
                $chunks = [];
                $sors   = [];
                foreach ($atts as $att) {
                    $chunks[] = "[{$att->original_name}]\n" . $att->promptSnippet(6000);
                    $sor = $att->extractStatementOfRequirements(8000);
                    if ($sor) {
                        $sors[] = "[{$att->original_name} · SoR]\n" . $sor;
                    }
                }
                $pdfBlock = "\n\nDocumentos do concurso (RFP/RFQ):\n---\n"
                          . implode("\n\n---\n\n", $chunks)
                          . "\n---\n\nUsa o conteúdo destes documentos para tornar cada email "
                          . "específico aos equipamentos / part-numbers / quantidades reais. "
                          . "NUNCA inventes números — se um detalhe não estiver no documento, omite-o.";

                if (!empty($sors)) {
                    $sorBlock = "\n\n=== STATEMENT OF REQUIREMENTS (SECÇÃO CRÍTICA) ===\n"
                              . "Esta é a secção do RFP onde estão as specs técnicas linha-a-linha. "
                              . "OBRIGATÓRIO mencionar TODOS os items / specs / normas que aqui aparecem "
                              . "no corpo de cada email — sem isto o fornecedor não sabe o que cotar.\n\n"
                              . implode("\n\n", $sors)
                              . "\n=== FIM STATEMENT OF REQUIREMENTS ===";
                }
            }
        } catch (\Throwable $e) { /* attachments relation missing — skip silently */ }

        // Explicit SHAPE B instruction so Daniel doesn't collapse to a single email.
        return <<<PROMPT
Concurso/RFQ:
  • Título: {$tender->title}
  • Referência: {$ref}
  • Organização: {$org}
  • Deadline: {$deadline}
  • Fonte: {$tender->source}{$pdfBlock}{$sorBlock}

Por favor escreve UM email tailored POR FORNECEDOR — usa o template "Quote Request" /
"Cold Outreach" conforme apropriado. {$langLine}

REGRA CRÍTICA — DETALHE TÉCNICO POR LINHA (pedido directo do operador 2026-05-18):
Sem o detalhe técnico de cada item, o fornecedor não sabe o que cotar. Por isso
o CORPO do email TEM de incluir uma TABELA / LISTA com TODAS as linhas de equipamento
extraídas do RFP, no formato:

    Item 1: <Descrição> · P/N <number> · Qty <N> · <especificações relevantes>
    Item 2: <Descrição> · P/N <number> · Qty <N> · <especificações relevantes>
    ...

Se o RFP usar Item code, NSN, modelo, dimensões, normas (CE-MDR, NATO Mil-Std, EUR.1),
INCLUI cada um. Não resumas em "vários equipamentos" — o fornecedor precisa de saber
exactamente o que cotar para ser útil.

Cada email deve:
  • Mencionar o concurso e a referência no assunto
  • LISTAR cada item técnico do RFP no corpo (não omitir — é o ponto crítico)
  • Pedir cotação para CADA linha + lead time + condições de pagamento
  • Ser específico ao portfolio do fornecedor — se o fornecedor só faz Item 1 e 3,
    foca-te neles e marca os outros como "Se também fornecem, indiquem"
  • Incluir a assinatura standard do PartYard
  • Ter um CTA claro com o deadline em mente
  • Mencionar normas / compliance se aparecerem no RFP (CE-MDR, NATO, EUR.1, ISO)

Lista de fornecedores:
{$supLines}

IMPORTANTE: devolve EXACTAMENTE no formato SHAPE B (objecto com array "emails"),
mesmo que sejam só 2 fornecedores. Não devolvas SHAPE A.{$noteBlock}
PROMPT;
    }

    /**
     * POST /tenders/{tender}/draft-emails-from-analysis
     *
     * 2026-05-18: gera 1 email por fornecedor MENCIONADO NA ANÁLISE
     * multi-agente — não a partir do directório H&P. Pedido directo:
     *   "deveria depois preparar os emails a perguntar os preços para
     *    cada fornecedor mencionado e analisado conforme as
     *    especificações na web"
     *
     * Diferente do flow draft() normal:
     *   • Não recebe supplier_ids (não há rows no directório)
     *   • Lê TenderServiceAnalysis (executive_summary + sections.*)
     *   • Daniel extrai os fornecedores mencionados na análise
     *     (Karl Storz, Medtronic, Interacoustics, etc.) com as
     *     linhas/items que cada um cobre
     *   • Devolve emails estruturados (SHAPE B) com tabela linha-a-linha
     *     por fornecedor
     */
    public function draftFromAnalysis(Request $request, Tender $tender, EmailAgent $daniel): JsonResponse
    {
        $this->authorizeTender($tender);

        if ($tender->is_confidential) {
            return response()->json([
                'error'  => 'tender_confidential',
                'detail' => 'Concurso confidencial — Daniel desligado.',
            ], 403);
        }

        $analysis = \App\Models\TenderServiceAnalysis::where('tender_id', $tender->id)
            ->where('status', 'done')
            ->first();
        if (!$analysis) {
            return response()->json([
                'error'  => 'no_analysis',
                'detail' => 'Este concurso ainda não tem análise multi-agente. Corre primeiro "🎯 Análise do serviço".',
            ], 422);
        }

        $language = $request->input('language', 'pt');
        $note     = trim((string) $request->input('note', ''));
        $langLine = match ($language) {
            'en' => 'Write the emails in English.',
            'es' => 'Escribe los emails en español.',
            default => 'Escreve os emails em português europeu (pt-PT).',
        };

        // Constrói contexto rico da análise: exec summary + cada secção
        // por agente. As secções têm key_points + risks + recommendations
        // + (importante) o texto livre dos summary com fornecedores
        // candidatos mencionados.
        $tender->load('attachments');
        $sors = [];
        foreach ($tender->attachments->where('extraction_status', 'ok') as $att) {
            $sor = $att->extractStatementOfRequirements(6000);
            if ($sor) $sors[] = "[{$att->original_name}]\n{$sor}";
        }
        $sorBlock = $sors ? "\n=== SoR ===\n" . implode("\n\n", $sors) . "\n=== FIM SoR ===\n" : '';

        $analysisBlock = "=== ANÁLISE MULTI-AGENTE ===\n"
            . ($analysis->executive_summary ?: '') . "\n\n";
        foreach ((array) $analysis->sections as $key => $sec) {
            $analysisBlock .= "--- " . ($sec['agent_name'] ?? $key) . " ---\n"
                            . ($sec['summary'] ?? '') . "\n";
            if (!empty($sec['key_points'])) {
                $analysisBlock .= "Pontos-chave:\n";
                foreach ((array) $sec['key_points'] as $kp) $analysisBlock .= "  • {$kp}\n";
            }
            if (!empty($sec['recommendations'])) {
                $analysisBlock .= "Recomendações:\n";
                foreach ((array) $sec['recommendations'] as $r) $analysisBlock .= "  • {$r}\n";
            }
            $analysisBlock .= "\n";
        }
        $analysisBlock .= "=== FIM ANÁLISE ===\n";

        $deadline = $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—';
        $ref      = $tender->reference ?: '—';

        // Prompt para o Daniel: extrair fornecedores da análise + 1 email
        // POR FORNECEDOR com a TABELA das linhas/items que esse cobre.
        $prompt = <<<PROMPT
Concurso/RFQ:
  • Título: {$tender->title}
  • Referência: {$ref}
  • Deadline: {$deadline}

{$sorBlock}

{$analysisBlock}

PEDIDO CRÍTICO:
A análise multi-agente acima identifica vários FORNECEDORES CANDIDATOS
(Karl Storz, Medtronic, Interacoustics, Olympus, Stryker, etc.) com as
LINHAS / ITEMS específicos que cada um cobre. {$langLine}

Para CADA fornecedor mencionado na análise, escreve UM email tailored
com este formato no corpo:

  • Saudação curta apresentando-se como PartYard Defense Procurement
  • Tabela COMPLETA das linhas/items que ESTE fornecedor cobre
    (Linha N · Descrição · Qty up to N · specs técnicas resumidas)
  • Pedido de cotação por linha + lead time + condições de pagamento
  • Compliance obrigatório (CE-MDR, NATO Mil-Std, EUR.1 conforme RFP)
  • CTA com deadline em mente
  • Assinatura PartYard

REGRAS:
  • NUNCA menciona o cliente final (NSPA, NCIA, NATO) — usar "RFP de
    defesa europeu" se precisar de contexto
  • Cada email é tailored ao portfolio do fornecedor — se Karl Storz só
    cobre Linha 30 e 50, foca-se nessas; outras linhas marca como
    "Adicionalmente, se também fornecem ENT microdebriders (Linha 40)
    indiquem"
  • Inclui valor estimado por linha quando a análise tenha (€X-Yk) como
    referência de mercado — ajuda o fornecedor a calibrar a oferta
  • Se a análise mencionar specs específicas da web (P/N, modelos), usa-as
  • Devolve EXACTAMENTE no formato SHAPE B (objecto com array "emails"),
    NUNCA SHAPE A

{$note}
PROMPT;

        try {
            $reply = $daniel->chat($prompt);
        } catch (\Throwable $e) {
            Log::warning('draftFromAnalysis failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'agent_error', 'detail' => $e->getMessage()], 502);
        }

        // Parse output — mesma lógica do draft() normal
        if (str_starts_with($reply, '__EMAILS__')) {
            $payload = json_decode(substr($reply, strlen('__EMAILS__')), true);
            return response()->json([
                'shape'    => 'multi',
                'emails'   => $payload['emails'] ?? [],
                'language' => $payload['language'] ?? $language,
                'source'   => 'analysis',
            ]);
        }
        if (str_starts_with($reply, '__EMAIL__')) {
            $single = json_decode(substr($reply, strlen('__EMAIL__')), true);
            return response()->json([
                'shape'  => 'single',
                'emails' => [$single],
                'language' => $single['language'] ?? $language,
                'source' => 'analysis',
            ]);
        }
        return response()->json([
            'shape'  => 'fallback',
            'text'   => $reply,
            'source' => 'analysis',
        ]);
    }

    private function authorizeTender(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if ($user->can('tenders.view-all')) return;
        // 2026-05-19: Acingov/Vortal/Anogov sao pool publico interno.
        if (in_array($tender->source, Tender::PUBLIC_SOURCES, true)) return;

        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) {
            abort(403, 'Este concurso não está atribuído a si.');
        }
    }
}
