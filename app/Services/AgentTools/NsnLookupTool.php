<?php

namespace App\Services\AgentTools;

use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * nsn_lookup — Pesquisa NSN (NATO Stock Number) na web e devolve
 * info estruturada: descrição, OEM, NCAGE, distribuidores, contactos.
 *
 * Pedido directo 2026-05-21: "liga me esta parte do droplete da
 * clawyard para os agentes pesquisarem os NSN os codigos quando
 * necessitam de saber quais os fornecedores e seus emails".
 *
 * NSN format: XXXX-XX-XXX-XXXX (13 dígitos com hifens) ou XXXXXXXXXXXXX:
 *   • FSC  (4) — Federal Supply Class (ex: 5331 O-rings)
 *   • NCB  (2) — NATO Country code (00/01 US, 99 UK, 17 NL, etc.)
 *   • NIIN (7) — National Item Identification Number
 *
 * Pipeline:
 *   1. Normalize NSN (strip non-digits, format XXXX-XX-XXX-XXXX)
 *   2. Cache hit? → return imediato (TTL 7d — NSN data stable)
 *   3. Tavily search com query optimizada (nsn site:nsnlookup.com etc.)
 *   4. Claude extrai JSON: description, oem, ncage_codes, distributors[]
 *   5. Cache result, devolve ao agente
 *
 * Custo: ~$0.008 (Tavily advanced + Haiku JSON extract). Cached 7d
 * portanto re-lookups grátis.
 *
 * Allow-listed em: mildef (Cor. Rodrigues), sales (Marco Sales),
 * engineer (Eng. Victor) — agentes que mais beneficiam de NSN info.
 */
class NsnLookupTool implements AgentToolInterface
{
    public function __construct(
        private WebSearchService $web,
        private AgentDispatcher $dispatcher,
    ) {}

    public function name(): string { return 'nsn_lookup'; }

    public function description(): string
    {
        return 'Procura um NSN (NATO Stock Number) e devolve descrição, OEM, '
             . 'NCAGE codes, distribuidores autorizados, e contactos (emails) '
             . 'quando disponíveis. Útil quando o tender menciona NSN específico '
             . 'e precisas de identificar quem fabrica + quem distribui (EU/US/UK '
             . 'preferred, exclui CN/RU). Aceita formato XXXX-XX-XXX-XXXX ou '
             . 'XXXXXXXXXXXXX (13 dígitos). Cache 7d.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nsn' => [
                    'type'        => 'string',
                    'description' => 'NSN code (13 dígitos com ou sem hifens). Ex: "5331-01-234-5678" ou "5331012345678".',
                ],
                'item_hint' => [
                    'type'        => 'string',
                    'description' => 'Opcional — descrição parcial do item se já a tens (ex: "O-ring 25mm"). Refina a pesquisa.',
                ],
            ],
            'required' => ['nsn'],
        ];
    }

    public function execute(array $input, array $context): array
    {
        $raw = (string) ($input['nsn'] ?? '');
        $nsn = $this->normalizeNsn($raw);
        if ($nsn === null) {
            return [
                'ok'    => false,
                'error' => "NSN inválido: '{$raw}'. Espera 13 dígitos (ex: 5331-01-234-5678).",
            ];
        }

        $hint = trim((string) ($input['item_hint'] ?? ''));

        // 1. Cache check — NSN data é stable, 7d é seguro
        $cacheKey = 'nsn_lookup:v1:' . $nsn . ($hint !== '' ? ':' . md5($hint) : '');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            Log::info('NsnLookupTool: cache hit', ['nsn' => $nsn]);
            return [
                'ok'       => true,
                'result'   => $this->formatResult($nsn, $cached),
                'cost_usd' => 0,
            ];
        }

        if (!$this->web->isAvailable()) {
            return ['ok' => false, 'error' => 'Tavily API não configurada.'];
        }

        // 2. Tavily — query optimizada para sites com NSN data
        $query = $this->buildQuery($nsn, $hint);
        try {
            $raw = $this->web->search($query, maxResults: 8, searchDepth: 'advanced');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Tavily falhou: ' . $e->getMessage()];
        }

        if (!is_string($raw) || mb_strlen($raw) < 50) {
            return ['ok' => false, 'error' => 'Tavily sem hits utilizáveis para NSN ' . $nsn];
        }

        // 3. Claude extrai estrutura — Haiku é suficiente para extracção
        try {
            $intel = $this->extractIntel($nsn, $hint, $raw);
        } catch (\Throwable $e) {
            Log::warning('NsnLookupTool: Claude extract failed', [
                'nsn'   => $nsn,
                'error' => $e->getMessage(),
            ]);
            // Fallback: devolve Tavily raw com warning
            return [
                'ok'       => true,
                'result'   => "Tavily raw (Claude extract falhou):\n" . mb_substr($raw, 0, 3000),
                'cost_usd' => 0.008,
            ];
        }

        // 4. Cache + devolve
        Cache::put($cacheKey, $intel, now()->addDays(7));

        Log::info('NsnLookupTool: lookup OK', [
            'nsn'             => $nsn,
            'oem'             => $intel['oem']             ?? '?',
            'distributors_n'  => count((array) ($intel['distributors'] ?? [])),
            'emails_n'        => count((array) ($intel['contact_emails'] ?? [])),
        ]);

        return [
            'ok'       => true,
            'result'   => $this->formatResult($nsn, $intel),
            'cost_usd' => 0.013,
        ];
    }

    /**
     * Normaliza NSN para formato canónico XXXX-XX-XXX-XXXX.
     * Devolve null se inválido (< ou > 13 dígitos).
     */
    private function normalizeNsn(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (mb_strlen((string) $digits) !== 13) return null;
        return mb_substr($digits, 0, 4) . '-'
             . mb_substr($digits, 4, 2) . '-'
             . mb_substr($digits, 6, 3) . '-'
             . mb_substr($digits, 9, 4);
    }

    private function buildQuery(string $nsn, string $hint): string
    {
        // Sites com info útil de NSN — público / catálogos / NCAGE registries
        // Preferimos resultados de nsncenter, dla.mil, nato.int, partsbase
        $bits = [
            'NSN ' . $nsn,
        ];
        if ($hint !== '') {
            $bits[] = '"' . mb_substr($hint, 0, 60) . '"';
        }
        $bits[] = 'manufacturer OR supplier OR distributor OR NCAGE OR CAGE';
        return mb_substr(implode(' ', $bits), 0, 380);
    }

    private function extractIntel(string $nsn, string $hint, string $tavilyRaw): array
    {
        $system = <<<PROMPT
És um analista de procurement do PartYard / HP-Group. Recebes:
  • Um NSN (NATO Stock Number) específico
  • Snippets de Tavily com referências a esse NSN

Devolve APENAS este JSON (sem markdown, sem prefácio):

{
  "nsn": "XXXX-XX-XXX-XXXX",
  "description": "descrição técnica do item (≤200 chars) — peça, função, specs",
  "fsc": "código FSC + nome (ex: '5331 — O-Rings')",
  "oem": "fabricante original (nome canónico, ≤80 chars)",
  "ncage_codes": ["códigos NCAGE/CAGE associados (5 chars cada)", ...],
  "distributors": [
    {"name": "nome distribuidor", "country": "ISO-2 ou nome curto",
     "role": "OEM" | "distributor" | "broker", "url": "https://..."},
    ... máx 5 ...
  ],
  "contact_emails": ["sales@oem.com", "info@distributor.com", ...máx 5...],
  "evidence_urls": ["https://..", "https://.."]
}

REGRAS:
  • EXCLUI fornecedores chineses/russos (política HP-Group security)
  • EXCLUI marketplaces (Alibaba, IndiaMart) e listings genéricos
  • PREFERE EU/US/UK/JP/KR/IL/CA
  • Se Tavily não tem info útil, devolve campos vazios — NÃO inventes
  • Emails têm de aparecer LITERALMENTE nos snippets — não inventes (.com/.eu/.pt etc)
  • description deve ser específica (não "spare part" genérico)
PROMPT;

        $userMsg = "NSN: {$nsn}\n";
        if ($hint !== '') $userMsg .= "Item hint (do operador): {$hint}\n";
        $userMsg .= "\n=== TAVILY HITS ===\n" . mb_substr($tavilyRaw, 0, 5000) . "\n=== FIM ===\n\nDevolve o JSON.";

        $haikuModel = (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001');

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    1200,
            model:        $haikuModel,
        );

        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException('Claude failed: ' . ($res['error'] ?? 'unknown'));
        }

        $raw = trim((string) ($res['text'] ?? ''));
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) {
            throw new \RuntimeException('LLM did not return JSON');
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON decode failed');
        }

        // Sanitize
        return [
            'nsn'             => $nsn,
            'description'     => mb_substr(trim((string) ($decoded['description'] ?? '')), 0, 200),
            'fsc'             => mb_substr(trim((string) ($decoded['fsc'] ?? '')), 0, 100),
            'oem'             => mb_substr(trim((string) ($decoded['oem'] ?? '')), 0, 80),
            'ncage_codes'     => array_slice(array_filter(array_map(
                fn ($c) => mb_substr(trim((string) $c), 0, 5),
                (array) ($decoded['ncage_codes'] ?? [])
            )), 0, 8),
            'distributors'    => array_slice(array_values(array_filter(
                array_map(function ($d) {
                    if (!is_array($d)) return null;
                    $name = trim((string) ($d['name'] ?? ''));
                    if ($name === '') return null;
                    $url = trim((string) ($d['url'] ?? ''));
                    return [
                        'name'    => mb_substr($name, 0, 80),
                        'country' => mb_substr(trim((string) ($d['country'] ?? '')), 0, 30),
                        'role'    => mb_substr(trim((string) ($d['role'] ?? '')), 0, 20),
                        'url'     => preg_match('#^https?://#', $url) ? mb_substr($url, 0, 500) : '',
                    ];
                }, (array) ($decoded['distributors'] ?? []))
            )), 0, 5),
            'contact_emails'  => array_slice(array_values(array_filter(
                array_map(fn ($e) => trim((string) $e), (array) ($decoded['contact_emails'] ?? [])),
                fn ($e) => preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $e)
            )), 0, 5),
            'evidence_urls'   => array_slice(array_values(array_filter(
                array_map(fn ($u) => trim((string) $u), (array) ($decoded['evidence_urls'] ?? [])),
                fn ($u) => preg_match('#^https?://#', $u)
            )), 0, 5),
        ];
    }

    private function formatResult(string $nsn, array $intel): string
    {
        $out = "NSN {$nsn}";
        if (!empty($intel['fsc']))         $out .= "\nFSC: " . $intel['fsc'];
        if (!empty($intel['description'])) $out .= "\nDescription: " . $intel['description'];
        if (!empty($intel['oem']))         $out .= "\nOEM: " . $intel['oem'];

        if (!empty($intel['ncage_codes'])) {
            $out .= "\nNCAGE codes: " . implode(', ', $intel['ncage_codes']);
        }

        if (!empty($intel['distributors'])) {
            $out .= "\n\nDistribuidores aprovados (preferred EU/US/UK):";
            foreach ($intel['distributors'] as $d) {
                $line = "  • {$d['name']}";
                if (!empty($d['country'])) $line .= " ({$d['country']})";
                if (!empty($d['role']))    $line .= " [{$d['role']}]";
                if (!empty($d['url']))     $line .= " — {$d['url']}";
                $out .= "\n" . $line;
            }
        }

        if (!empty($intel['contact_emails'])) {
            $out .= "\n\nContactos detectados (verificar antes de usar):";
            foreach ($intel['contact_emails'] as $e) {
                $out .= "\n  → {$e}";
            }
        }

        if (!empty($intel['evidence_urls'])) {
            $out .= "\n\nEvidence:";
            foreach ($intel['evidence_urls'] as $u) {
                $out .= "\n  - {$u}";
            }
        }

        return $out;
    }
}
