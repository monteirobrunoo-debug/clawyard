<?php

namespace App\Services;

use App\Models\Supplier;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Auto-enriches a supplier row by searching the web (Tavily) for
 * contact info, then asking Claude to extract structured fields
 * (website, primary_email, phones, country) from the snippets.
 *
 * Why two stages (Tavily + Claude) instead of just regex on Tavily
 * snippets:
 *   • Snippets are messy — emails are sometimes obfuscated ("contact
 *     [at] wartsila.com"), phones come in 12 formats, the canonical
 *     website often isn't the first hit (Wikipedia, LinkedIn,
 *     directories rank above corp .com).
 *   • Claude reads the snippets in context and applies common-sense
 *     ("this is a press release, not a contact page — skip").
 *
 * The cost per call is ~$0.001 Tavily + ~$0.005 Claude = $0.006.
 * Enriching all 805 suppliers once = ~$5. Trivial.
 *
 * Safety:
 *   • Never overwrites an existing primary_email or website (the Excel
 *     and manual entries are authoritative for those fields). Findings
 *     accumulate into additional_emails / phones via the merge helpers.
 *   • Bumps enrich_attempts every run; rows that consistently return
 *     nothing (3+ attempts, still no email) drop out of the cron queue
 *     so we stop hammering Tavily for them.
 */
class SupplierEnrichmentService
{
    public function __construct(
        private WebSearchService $web,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   updated: array<string>,           // list of fields actually changed
     *   reason?: string,                  // populated when ok=false
     *   raw_query?: string,
     *   raw_extraction?: array<string,mixed>,
     * }
     */
    public function enrichOne(Supplier $supplier): array
    {
        if (!$this->web->isAvailable()) {
            return ['ok' => false, 'updated' => [], 'reason' => 'tavily_not_configured'];
        }

        $query = $this->buildQuery($supplier);

        try {
            $rawSearch = $this->web->search($query, maxResults: 5, searchDepth: 'advanced');
        } catch (\Throwable $e) {
            $this->bumpAttempts($supplier);
            Log::warning('SupplierEnrichmentService: Tavily failed', [
                'supplier_id' => $supplier->id,
                'error'       => $e->getMessage(),
            ]);
            return ['ok' => false, 'updated' => [], 'reason' => 'tavily_error: ' . $e->getMessage()];
        }

        $extraction = $this->extractWithClaude($supplier, $rawSearch);
        if (!$extraction) {
            $this->bumpAttempts($supplier);
            return [
                'ok' => false, 'updated' => [], 'reason' => 'extraction_failed',
                'raw_query' => $query,
            ];
        }

        $changed = $this->mergeIntoSupplier($supplier, $extraction);
        $supplier->enriched_at = now();
        $supplier->enrich_attempts = ($supplier->enrich_attempts ?? 0) + 1;
        // Stash the extraction for audit / debugging without nuking
        // the original ingestion meta.
        $meta = (array) ($supplier->source_meta ?? []);
        $meta['last_enrichment'] = [
            'at'         => now()->toIso8601String(),
            'query'      => $query,
            'extraction' => $extraction,
        ];
        $supplier->source_meta = $meta;
        $supplier->save();

        return [
            'ok'             => true,
            'updated'        => $changed,
            'raw_query'      => $query,
            'raw_extraction' => $extraction,
        ];
    }

    private function buildQuery(Supplier $supplier): string
    {
        // Use the canonical name + a procurement-flavoured tail so we
        // get the supplier's official site, not a random press release.
        // Adding "contact email" tilts results toward About/Contact pages.
        $name = $supplier->name;
        $bits = ['"' . $name . '"', 'contact email website'];
        if (!empty($supplier->brands)) {
            $bits[] = (string) $supplier->brands[0];
        }
        return implode(' ', $bits);
    }

    /**
     * @return array{
     *   website: ?string,
     *   primary_email: ?string,
     *   additional_emails: array<string>,
     *   phones: array<string>,
     *   country_code: ?string,
     *   confidence: string
     * }|null
     */
    private function extractWithClaude(Supplier $supplier, string $rawSearch): ?array
    {
        $system = <<<'PROMPT'
You are a contact-extraction agent. The user gives you a supplier name
and a chunk of web search snippets. Extract the supplier's contact info
into strict JSON.

OUTPUT — return ONLY this JSON, no markdown, no commentary:
{
  "website": "https://example.com or null",
  "primary_email": "primary@example.com or null",
  "additional_emails": ["sales@example.com", "info@example.com"],
  "phones": ["+351 21 000 0000"],
  "country_code": "PT or null (ISO 3166-1 alpha-2)",
  "confidence": "high | medium | low"
}

RULES:
  • NEVER invent. If you don't see it in the snippets, leave the field
    null (or empty array).
  • Pick the OFFICIAL company website, not directory pages
    (Wikipedia, Bloomberg, LinkedIn). The official site usually shares
    the company's domain in its URL.
  • Skip mailto: addresses that look generic ("press@", "media@",
    "no-reply@") UNLESS no other email is present.
  • Phones: keep them in the format the snippet shows; don't
    "reformat" them.
  • country_code: derive from the website TLD or address mention if
    obvious; otherwise null. Don't guess from the supplier name alone.
  • confidence: "high" only when the website AND at least one email
    came directly from a snippet that named the supplier verbatim.
PROMPT;

        $user = "Supplier: \"" . $supplier->name . "\"\n\n";
        if (!empty($supplier->brands)) {
            $user .= "Known brands: " . implode(', ', (array) $supplier->brands) . "\n";
        }
        $user .= "\nSearch results:\n---\n" . mb_substr($rawSearch, 0, 6000) . "\n---\n";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $user,
            maxTokens:    600,
        );

        if (!($res['ok'] ?? false)) {
            Log::warning('SupplierEnrichmentService: Claude failed', [
                'supplier_id' => $supplier->id,
                'error'       => $res['error'] ?? 'unknown',
            ]);
            return null;
        }

        $text = trim((string) ($res['text'] ?? ''));
        if ($text === '') return null;

        // Strip markdown code fences if present.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        if (!preg_match('/\{[\s\S]*\}/', $text, $m)) return null;

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) return null;

        return [
            'website'           => $this->cleanWebsite($decoded['website']           ?? null),
            'primary_email'     => $this->cleanEmail  ($decoded['primary_email']     ?? null),
            'additional_emails' => array_values(array_filter(array_map(
                fn($e) => $this->cleanEmail($e),
                (array) ($decoded['additional_emails'] ?? [])
            ))),
            'phones'            => array_values(array_filter(array_map(
                fn($p) => trim((string) $p),
                (array) ($decoded['phones'] ?? [])
            ))),
            'country_code'      => $this->cleanCountry($decoded['country_code'] ?? null),
            'confidence'        => (string) ($decoded['confidence'] ?? 'low'),
        ];
    }

    private function mergeIntoSupplier(Supplier $supplier, array $extraction): array
    {
        $changed = [];

        // Website — only fill if currently empty (don't overwrite manual
        // entries or Excel data even if Tavily disagrees).
        if (empty($supplier->website) && !empty($extraction['website'])) {
            $supplier->website = $extraction['website'];
            $changed[] = 'website';
        }

        // Country — same rule.
        if (empty($supplier->country_code) && !empty($extraction['country_code'])) {
            $supplier->country_code = $extraction['country_code'];
            $changed[] = 'country_code';
        }

        // Primary email — fill if empty; otherwise demote the new one
        // to additional_emails. mergeEmail handles both paths + dedup.
        $hadEmail = !empty($supplier->primary_email);
        if (!empty($extraction['primary_email'])) {
            $supplier->mergeEmail($extraction['primary_email']);
            if (!$hadEmail && !empty($supplier->primary_email)) $changed[] = 'primary_email';
        }
        $beforeAdd = count((array) ($supplier->additional_emails ?? []));
        foreach ($extraction['additional_emails'] as $em) {
            $supplier->mergeEmail($em);
        }
        $afterAdd = count((array) ($supplier->additional_emails ?? []));
        if ($afterAdd > $beforeAdd) $changed[] = 'additional_emails';

        // Phones — union with existing.
        if (!empty($extraction['phones'])) {
            $existing = (array) ($supplier->phones ?? []);
            $merged = array_values(array_unique(array_merge($existing, $extraction['phones'])));
            if (count($merged) > count($existing)) {
                $supplier->phones = $merged;
                $changed[] = 'phones';
            }
        }

        return $changed;
    }

    private function bumpAttempts(Supplier $s): void
    {
        $s->enrich_attempts = ($s->enrich_attempts ?? 0) + 1;
        $s->save();
    }

    // ── Cleanup helpers ────────────────────────────────────────────────

    private function cleanWebsite(?string $url): ?string
    {
        if (!$url) return null;
        $url = trim($url);
        if ($url === '' || strtolower($url) === 'null') return null;
        // Add scheme if missing — many extractions return "wartsila.com"
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
        // Sanity: must have a dot in the host.
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (!str_contains($host, '.')) return null;
        return mb_substr($url, 0, 255);
    }

    private function cleanEmail(?string $email): ?string
    {
        if (!$email) return null;
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || strtolower($email) === 'null') return null;
        // De-obfuscate common patterns ("foo [at] bar.com" → "foo@bar.com").
        $email = preg_replace('/\s*\[?\s*at\s*\]?\s*/i', '@', $email) ?? $email;
        $email = preg_replace('/\s*\[?\s*dot\s*\]?\s*/i', '.', $email) ?? $email;
        $email = preg_replace('/\s+/', '', $email) ?? $email;
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function cleanCountry(?string $code): ?string
    {
        if (!$code) return null;
        $code = strtoupper(trim((string) $code));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }
}
