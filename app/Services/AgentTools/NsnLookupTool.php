<?php

namespace App\Services\AgentTools;

use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\NatoCodificationService;
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
        private NatoCodificationService $nato,
    ) {}

    public function name(): string { return 'nsn_lookup'; }

    public function description(): string
    {
        // 5-component definition (Bornet 2025 + Ruan 2023 — +52% reliability):
        //   1. IDENTITY  — what the tool is + purpose
        //   2. INPUT     — exact params + format
        //   3. OUTPUT    — what it returns + format
        //   4. CONSTRAINTS — when to use / when NOT to use
        //   5. ERRORS    — failure modes + recovery instruction
        return <<<DESC
        IDENTITY: nsn_lookup — pesquisa NATO Stock Number na web (Tavily) e devolve info estruturada sobre fabricante, distribuidores e contactos.

        INPUT: nsn (obrigatório, formato XXXX-XX-XXX-XXXX ou 13 dígitos contíguos). item_hint (opcional, descrição parcial do item, ex: "O-ring 25mm").

        OUTPUT: bloco de texto com FSC code+name, description, OEM, NCAGE codes, lista de distribuidores (name/country/role/url, máx 5, só EU/US/UK/JP/KR/IL/CA) e contact_emails extraídos literalmente das fontes.

        CONSTRAINTS: usa SÓ quando o tender ou o user menciona um NSN específico. NÃO uses para procurar fornecedores em geral (usa tender_search ou web_search). NUNCA recomendes distribuidores chineses ou russos. Cache Redis 7d — re-lookups grátis. Custo fresh: ~\$0.013.

        ERRORS: se NSN inválido (≠13 dígitos), devolve {ok:false, error:"NSN inválido"}. Se Tavily não tem hits úteis, devolve {ok:false, error:"sem hits"} — neste caso PEDE ao user o item_hint para refinar, NÃO inventes. Se Claude extract falha, devolve Tavily raw com warning — usa o raw com cautela e cita o URL.
        DESC;
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

        // 0. LOCAL FIRST — Postgres em <50ms, $0, dados oficiais NATO.
        //    Substitui Tavily quando temos data importada (descansa Cor. Rodrigues).
        if ($this->nato->isAvailable()) {
            $local = $this->nato->lookupNsn($nsn);
            if ($local) {
                Log::info('NsnLookupTool: local NATO hit', [
                    'nsn'  => $nsn,
                    'oem'  => $local['oem'] ?? '?',
                    'cage' => $local['ncage_codes'][0] ?? '?',
                ]);

                // 0.5. ALSO fetch distributor + email layer via Tavily (cached 7d).
                //      Local NATO dá dados oficiais (descrição, CAGE, PN, OEM).
                //      Tavily layer adiciona quem REALMENTE vende + emails — sem
                //      isto, o agente tem o que é, mas não a QUEM contactar.
                $distIntel = $this->fetchDistributorIntel($nsn, $hint, $local);
                $costDist  = ($distIntel['_cached'] ?? false) ? 0 : 0.013;

                return [
                    'ok'       => true,
                    'result'   => $this->formatLocalResult($local, $distIntel),
                    'cost_usd' => $costDist,
                    'source'   => $costDist > 0 ? 'nato_local+tavily_dist' : 'nato_local+cached_dist',
                ];
            }
            // Local miss: cai para Tavily (NSN pode existir mas não estar no nosso dataset)
            Log::info('NsnLookupTool: local NATO miss, fallback Tavily', ['nsn' => $nsn]);
        }

        // 1. Cache check — NSN data é stable, 7d é seguro
        $cacheKey = 'nsn_lookup:v1:' . $nsn . ($hint !== '' ? ':' . md5($hint) : '');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            Log::info('NsnLookupTool: cache hit', ['nsn' => $nsn]);
            return [
                'ok'       => true,
                'result'   => $this->formatResult($nsn, $cached),
                'cost_usd' => 0,
                'source'   => 'tavily_cache',
            ];
        }

        if (!$this->web->isAvailable()) {
            return ['ok' => false, 'error' => 'Tavily API não configurada e NSN não está no dataset local NATO.'];
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
                'source'   => 'tavily_raw',
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
            'source'   => 'tavily_fresh',
        ];
    }

    /**
     * Fetch Tavily-based distributor/email intel layered ON TOP of local hit.
     * Cached 7d por NSN (NSN data é stable, distribuidores mudam pouco).
     *
     * @return array{distributors:array,contact_emails:array,evidence_urls:array,_cached:bool}|null
     */
    private function fetchDistributorIntel(string $nsn, string $hint, array $local): ?array
    {
        $cacheKey = 'nsn_dist:v1:' . $nsn;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['_cached'] = true;
            return $cached;
        }

        if (!$this->web->isAvailable()) return null;

        // Query enriquecida com hints do local hit (descrição em turco + OEM PN ajudam Tavily)
        $bits = ['NSN ' . $nsn];
        if (!empty($local['oem']))             $bits[] = $local['oem'];
        if (!empty($local['manufacturer_pn'])) $bits[] = '"' . $local['manufacturer_pn'] . '"';
        if (!empty($local['description']))     $bits[] = '"' . mb_substr($local['description'], 0, 50) . '"';
        if ($hint !== '')                      $bits[] = '"' . mb_substr($hint, 0, 60) . '"';
        $bits[] = 'distributor OR supplier OR contact email';
        $query = mb_substr(implode(' ', $bits), 0, 380);

        try {
            $raw = $this->web->search($query, maxResults: 6, searchDepth: 'basic');
        } catch (\Throwable $e) {
            Log::warning('NsnLookupTool: dist Tavily failed', [
                'nsn' => $nsn, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_string($raw) || mb_strlen($raw) < 50) return null;

        try {
            $intel = $this->extractDistributorIntel($nsn, $local, $raw);
        } catch (\Throwable $e) {
            Log::warning('NsnLookupTool: dist Haiku extract failed', [
                'nsn' => $nsn, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        Cache::put($cacheKey, $intel, now()->addDays(7));
        $intel['_cached'] = false;
        return $intel;
    }

    /**
     * Haiku extrai SÓ distribuidores + emails dado o contexto local.
     */
    private function extractDistributorIntel(string $nsn, array $local, string $tavilyRaw): array
    {
        $context = "NSN: {$nsn}";
        if (!empty($local['description']))     $context .= "\nDescription: " . $local['description'];
        if (!empty($local['oem']))             $context .= "\nOEM: " . $local['oem'];
        if (!empty($local['manufacturer_pn'])) $context .= "\nOEM Part Number: " . $local['manufacturer_pn'];
        if (!empty($local['fsc']))             $context .= "\nFSC: " . $local['fsc'];

        $system = <<<PROMPT
És analista de procurement do PartYard / HP-Group. Acabaste de receber dados
oficiais NATO sobre um NSN (descrição, OEM, part number). Agora a tua tarefa
é encontrar QUEM vende este item — distribuidores aprovados + emails de
contacto reais.

Devolve APENAS este JSON (sem markdown, sem prefácio):

{
  "distributors": [
    {"name": "nome distribuidor", "country": "ISO-2 ou nome curto",
     "role": "OEM" | "distributor" | "broker", "url": "https://..."},
    ... máx 5 ...
  ],
  "contact_emails": ["sales@oem.com", "info@distributor.com", ... máx 5],
  "evidence_urls": ["https://..", "https://..", ... máx 5]
}

REGRAS DE EVIDÊNCIA (anti-hallucination):
  • Distribuidor: nome+URL têm de aparecer literalmente nos snippets — não
    inferir.
  • Emails: têm de aparecer LITERALMENTE no texto. Zero tolerância para
    .com/.eu inventados.
  • Se não tens evidência, devolve listas vazias. FALSO NEGATIVO É SEMPRE
    MELHOR QUE FALSO POSITIVO — operador humano vai contactar estes leads.

REGRAS DE POLÍTICA:
  • EXCLUI fornecedores chineses (.cn) / russos (.ru) — política HP-Group.
  • EXCLUI marketplaces (Alibaba, eBay, IndiaMart).
  • PREFERE EU/US/UK/JP/KR/IL/CA.
PROMPT;

        $userMsg = $context . "\n\n=== TAVILY HITS ===\n"
                 . mb_substr($tavilyRaw, 0, 4000)
                 . "\n=== FIM ===\n\nDevolve o JSON.";

        $haikuModel = (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001');

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    800,
            model:        $haikuModel,
        );

        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException('Haiku failed: ' . ($res['error'] ?? 'unknown'));
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

        // Defesa: nomes/emails têm de aparecer no Tavily raw
        $haystack = mb_strtolower($tavilyRaw);
        $appearsInTavily = function (string $needle) use ($haystack): bool {
            $needle = trim($needle);
            if (mb_strlen($needle) < 3) return false;
            return str_contains($haystack, mb_strtolower($needle));
        };

        return [
            'distributors'    => array_slice(array_values(array_filter(
                array_map(function ($d) use ($appearsInTavily) {
                    if (!is_array($d)) return null;
                    $name = trim((string) ($d['name'] ?? ''));
                    if ($name === '' || !$appearsInTavily($name)) return null;
                    $url = trim((string) ($d['url'] ?? ''));
                    return [
                        'name'    => mb_substr($name, 0, 80),
                        'country' => mb_substr(trim((string) ($d['country'] ?? '')), 0, 30),
                        'role'    => mb_substr(trim((string) ($d['role']    ?? '')), 0, 20),
                        'url'     => preg_match('#^https?://#', $url) ? mb_substr($url, 0, 500) : '',
                    ];
                }, (array) ($decoded['distributors'] ?? []))
            )), 0, 5),
            'contact_emails'  => array_slice(array_values(array_filter(
                array_map(fn ($e) => trim((string) $e), (array) ($decoded['contact_emails'] ?? [])),
                fn ($e) => preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $e)
                       && str_contains(mb_strtolower($e), '@')
                       && str_contains(mb_strtolower($tavilyRaw), mb_strtolower($e))
            )), 0, 5),
            'evidence_urls'   => array_slice(array_values(array_filter(
                array_map(fn ($u) => trim((string) $u), (array) ($decoded['evidence_urls'] ?? [])),
                fn ($u) => preg_match('#^https?://#', $u)
            )), 0, 5),
            '_cached'         => false,
        ];
    }

    /**
     * Formata resultado vindo do dataset local NATO (NatoCodificationService).
     * Estrutura é diferente do Tavily (temos manufacturer completo), portanto
     * tem o seu próprio formatter — mais rico em dados oficiais.
     */
    /**
     * 2026-05-28: extrai apenas o domínio do URL para evitar 404 quando
     * páginas específicas de produto dos distribuidores são removidas.
     * Pedido directo Bruno: "Cor. Rodrigues mostra WBParts (US) — wbparts.com,
     * sem link directo, user procura manualmente — mais conservador, sem
     * promessas que partem".
     *   https://www.wbparts.com/rfq/1560-00-806-5287.html → wbparts.com
     *   https://aerospaceunlimited.com/path                → aerospaceunlimited.com
     */
    private function domainOnly(string $url): string
    {
        if (!preg_match("#^https?://#i", $url)) return $url;
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        // Remove www. prefix por consistência visual.
        return preg_replace("/^www\\./i", "", strtolower($host));
    }

    private function formatLocalResult(array $local, ?array $distIntel = null): string
    {
        $nsn = (string) ($local['nsn'] ?? '');
        $out = "NSN {$nsn}  [fonte: dataset NATO local — dados oficiais]";

        if (!empty($local['fsc']))             $out .= "\nFSC: " . $local['fsc'];
        if (!empty($local['description']))     $out .= "\nDescription: " . $local['description'];
        if (!empty($local['unit_of_issue']))   $out .= "\nUnit of Issue: " . $local['unit_of_issue'];
        if (!empty($local['manufacturer_pn'])) $out .= "\nPart Number (OEM): " . $local['manufacturer_pn'];
        if (!empty($local['hazmat']))          $out .= "\nHazardous Material Code: " . $local['hazmat'];

        // Status / obsolescência — crítico para procurement
        if (!empty($local['status_code'])) {
            $out .= "\n⚠ Status code: " . $local['status_code'] . ' (verificar se item activo)';
        }
        if (!empty($local['replaced_by'])) {
            $out .= "\n🔁 NSN substituído por: " . $local['replaced_by'];
            if (!empty($local['replaced_by_2'])) $out .= " (alt: " . $local['replaced_by_2'] . ")";
        }

        if (!empty($local['ncb'])) {
            $line = "NCB: " . $local['ncb'];
            if (!empty($local['ncb_country'])) $line .= " ({$local['ncb_country']})";
            $out .= "\n" . $line;
        }

        if (!empty($local['oem']))     $out .= "\nOEM: " . $local['oem'];

        $mfg = $local['manufacturer'] ?? null;
        if (is_array($mfg)) {
            $out .= "\n\nFabricante (NCAGE oficial):";
            $out .= "\n  • CAGE: " . ($mfg['cage_code'] ?? '?');
            if (!empty($mfg['name']))     $out .= "\n  • Nome: " . $mfg['name'];
            if (!empty($mfg['country']))  $out .= "\n  • País: " . $mfg['country'];
            if (!empty($mfg['city']))     $out .= "\n  • Cidade: " . $mfg['city'];
            if (!empty($mfg['address']))  $out .= "\n  • Morada: " . $mfg['address'];
            if (!empty($mfg['postcode'])) $out .= "\n  • CP: " . $mfg['postcode'];
            if (!empty($mfg['phone']))    $out .= "\n  • Tel: " . $mfg['phone'];
            if (!empty($mfg['email']))    $out .= "\n  • Email: " . $mfg['email'];
            if (!empty($mfg['website']))  $out .= "\n  • Web: " . $this->domainOnly((string) $mfg['website']);  // 2026-05-28: só domínio
            if (!empty($mfg['status']))   $out .= "\n  • Estado: " . $mfg['status'];
        }

        // Distribuidores + emails (Tavily layer cached 7d) — quem REALMENTE vende
        if ($distIntel && (!empty($distIntel['distributors']) || !empty($distIntel['contact_emails']))) {
            if (!empty($distIntel['distributors'])) {
                $out .= "\n\nDistribuidores identificados (preferred EU/US/UK):";
                foreach ($distIntel['distributors'] as $d) {
                    $line = "  • {$d['name']}";
                    if (!empty($d['country'])) $line .= " ({$d['country']})";
                    if (!empty($d['role']))    $line .= " [{$d['role']}]";
                    if (!empty($d['url']))     $line .= " — " . $this->domainOnly((string) $d['url']);
                    $out .= "\n" . $line;
                }
            }

            if (!empty($distIntel['contact_emails'])) {
                $out .= "\n\nContactos email (verificar antes de usar):";
                foreach ($distIntel['contact_emails'] as $e) {
                    $out .= "\n  → {$e}";
                }
            }

            if (!empty($distIntel['evidence_urls'])) {
                $out .= "\n\nEvidence:";
                foreach ($distIntel['evidence_urls'] as $u) {
                    $out .= "\n  - " . $this->domainOnly((string) $u);  // 2026-05-28: só domínio
                }
            }

            $out .= ($distIntel['_cached'] ?? false)
                ? "\n\n(NATO oficial + distribuidores cached 7d — $0)"
                : "\n\n(NATO oficial + distribuidores fresh Tavily — ~\$0.013, cached 7d)";
        } else {
            $out .= "\n\n(Fonte oficial NATO — distribuidores não encontrados via web.)";
        }

        return $out;
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
  "oem": "fabricante original (nome canónico, ≤80 chars) OU string vazia",
  "ncage_codes": ["códigos NCAGE/CAGE associados (5 chars cada)", ...],
  "distributors": [
    {"name": "nome distribuidor", "country": "ISO-2 ou nome curto",
     "role": "OEM" | "distributor" | "broker", "url": "https://..."},
    ... máx 5 ...
  ],
  "contact_emails": ["sales@oem.com", "info@distributor.com", ...máx 5...],
  "evidence_urls": ["https://..", "https://.."]
}

REGRAS DE EVIDÊNCIA (CRÍTICO — anti-hallucination):
  • OEM: APENAS preenche se o nome do fabricante aparecer LITERALMENTE
    associado ao NSN num snippet ("Manufacturer: X", "OEM: X", "Made by X",
    "Cage code XXXXX = X"). Se vês um nome de empresa solto perto do NSN
    mas SEM relação explícita ao item, deixa vazio. NUNCA infiras OEM a
    partir de nomes que aparecem por coincidência geográfica/temporal.
  • Exemplos de FALSO POSITIVO a evitar: snippet menciona "Oshkosh
    Corporation" como cliente do FSC, não como fabricante do NSN → NÃO
    é OEM. Empresa aparece no header da página → NÃO é OEM.
  • Distribuidores: nome+URL têm de aparecer juntos nos snippets — não
    inferir distribuidor a partir de "vimos esta peça em X" sem que X
    venda esta peça especificamente.
  • Emails: têm de aparecer LITERALMENTE no texto dos snippets — não
    inventes (zero tolerância para .com/.eu/.pt inventados).
  • Se NÃO tens evidência directa de qualquer campo, devolve-o vazio.
    PREFERE FALSO NEGATIVO A FALSO POSITIVO — o agente humano que recebe
    isto vai actuar com base nestes dados. Mentir é pior que admitir
    "não sei".

REGRAS DE POLÍTICA:
  • EXCLUI fornecedores chineses/russos (política HP-Group security)
  • EXCLUI marketplaces (Alibaba, IndiaMart, eBay) e listings genéricos
  • PREFERE EU/US/UK/JP/KR/IL/CA
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

        // Defesa-em-profundidade contra alucinação Haiku: se o nome do OEM
        // ou de um distribuidor não aparece NO TEXTO ORIGINAL do Tavily,
        // dropa silenciosamente. Match case-insensitive em ASCII strip
        // (lida com acentos / caracteres especiais).
        $haystack = mb_strtolower($tavilyRaw);
        $appearsInTavily = function (string $name) use ($haystack): bool {
            $name = trim($name);
            if (mb_strlen($name) < 3) return false;
            // Tenta nome inteiro, depois primeira palavra (≥4 chars).
            if (str_contains($haystack, mb_strtolower($name))) return true;
            $first = explode(' ', $name)[0] ?? '';
            return mb_strlen($first) >= 4 && str_contains($haystack, mb_strtolower($first));
        };

        $oemRaw = trim((string) ($decoded['oem'] ?? ''));
        if ($oemRaw !== '' && !$appearsInTavily($oemRaw)) {
            Log::info('NsnLookupTool: OEM dropped (no Tavily evidence)', [
                'nsn' => $nsn, 'oem_claimed' => $oemRaw,
            ]);
            $decoded['oem'] = '';
        }

        $decoded['distributors'] = array_values(array_filter(
            (array) ($decoded['distributors'] ?? []),
            function ($d) use ($appearsInTavily, $nsn) {
                if (!is_array($d)) return false;
                $name = trim((string) ($d['name'] ?? ''));
                $ok = $name !== '' && $appearsInTavily($name);
                if (!$ok) {
                    Log::info('NsnLookupTool: distributor dropped (no Tavily evidence)', [
                        'nsn' => $nsn, 'name' => $name,
                    ]);
                }
                return $ok;
            }
        ));

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
                if (!empty($d['url']))     $line .= " — " . $this->domainOnly((string) $d['url']);
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
                $out .= "\n  - " . $this->domainOnly((string) $u);  // 2026-05-28: só domínio
            }
        }

        return $out;
    }
}
