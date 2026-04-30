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

        $includeWeb = $request->boolean('include_web', true);

        $bundle = $svc->suggest($tender, localLimit: 12, includeWeb: $includeWeb);

        return response()->json([
            'categories'    => $bundle['categories'],
            'web_query'     => $bundle['query'],
            'web_available' => $bundle['web_available'],
            'local'         => $bundle['local']->map(fn(Supplier $s) => [
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
            ])->values()->all(),
            'web' => $bundle['web'],
        ]);
    }

    public function draft(Request $request, Tender $tender, EmailAgent $daniel): JsonResponse
    {
        $this->authorizeTender($tender);

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

        // Explicit SHAPE B instruction so Daniel doesn't collapse to a single email.
        return <<<PROMPT
Concurso/RFQ:
  • Título: {$tender->title}
  • Referência: {$ref}
  • Organização: {$org}
  • Deadline: {$deadline}
  • Fonte: {$tender->source}

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
