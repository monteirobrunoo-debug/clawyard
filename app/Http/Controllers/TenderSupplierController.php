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
        $pdfBlock = '';
        try {
            $atts = $tender->attachments()->where('extraction_status', 'ok')->get();
            if ($atts->isNotEmpty()) {
                $chunks = [];
                foreach ($atts as $att) {
                    $chunks[] = "[{$att->original_name}]\n" . $att->promptSnippet(6000);
                }
                $pdfBlock = "\n\nDocumentos do concurso (RFP/RFQ):\n---\n"
                          . implode("\n\n---\n\n", $chunks)
                          . "\n---\n\nUsa o conteúdo destes documentos para tornar cada email "
                          . "específico aos equipamentos / part-numbers / quantidades reais. "
                          . "NUNCA inventes números — se um detalhe não estiver no documento, omite-o.";
            }
        } catch (\Throwable $e) { /* attachments relation missing — skip silently */ }

        // Explicit SHAPE B instruction so Daniel doesn't collapse to a single email.
        return <<<PROMPT
Concurso/RFQ:
  • Título: {$tender->title}
  • Referência: {$ref}
  • Organização: {$org}
  • Deadline: {$deadline}
  • Fonte: {$tender->source}{$pdfBlock}

Por favor escreve UM email tailored POR FORNECEDOR — usa o template "Quote Request" /
"Cold Outreach" conforme apropriado. {$langLine}

Cada email deve:
  • Mencionar o concurso e a referência no assunto
  • Pedir cotação / disponibilidade para os equipamentos relevantes
  • Ser específico ao portfolio de cada fornecedor (não copy-paste com nome trocado)
  • Incluir a assinatura standard do PartYard
  • Ter um CTA claro com o deadline em mente

Lista de fornecedores:
{$supLines}

IMPORTANTE: devolve EXACTAMENTE no formato SHAPE B (objecto com array "emails"),
mesmo que sejam só 2 fornecedores. Não devolvas SHAPE A.{$noteBlock}
PROMPT;
    }

    private function authorizeTender(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if ($user->can('tenders.view-all')) return;

        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) {
            abort(403, 'Este concurso não está atribuído a si.');
        }
    }
}
