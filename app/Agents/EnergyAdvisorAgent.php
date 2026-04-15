<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Models\Report;
use Illuminate\Support\Facades\Log;

/**
 * EnergyAdvisorAgent — "Eng. Sofia Energia"
 *
 * Consultor estratégico de energia marítima do HP-Group.
 * Implementa o framework Fuzzy TOPSIS (PeerJ CS 10.7717/peerj-cs.3625)
 * para ranquear alternativas de combustível/energia para frotas marítimas.
 *
 * Critérios (5): Effectiveness, Adaptability, Robustness,
 *                Stakeholder Engagement, Decision Quality
 * Alternativas (6): LNG, Biocombustível, Hidrogénio,
 *                   Propulsão Eléctrica, Híbrido, Status Quo (fuel convencional)
 */
class EnergyAdvisorAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'energy_intel';
    protected array  $contextTags = ['energia','energy','CII','EEXI','LNG','biofuel','decarbonisation','emissões','GHG'];

    protected Client $client;

    // ─── Fuzzy Triangular Scale ────────────────────────────────────────────
    // Linguistic → (L, M, U) as per Alghassab (2026) PeerJ CS 3625
    protected array $fuzzyScale = [
        'VL' => [1, 1, 3],   // Very Low
        'L'  => [1, 3, 5],   // Low
        'M'  => [3, 5, 7],   // Medium
        'H'  => [5, 7, 9],   // High
        'VH' => [7, 9, 9],   // Very High
    ];

    // ─── Default decision matrix ───────────────────────────────────────────
    // Rows = alternatives (fuel/energy options)
    // Cols = criteria [Effectiveness, Adaptability, Robustness, Stakeholders, DecisionQuality]
    // Values are linguistic terms → will be converted to fuzzy numbers
    // Based on paper Table 3 adapted to maritime fuel context
    protected array $defaultMatrix = [
        'LNG'       => ['H',  'H',  'VH', 'H',  'H' ],
        'Biofuel'   => ['H',  'VH', 'H',  'VH', 'H' ],
        'Hydrogen'  => ['VH', 'M',  'M',  'H',  'VH'],
        'Electric'  => ['H',  'H',  'H',  'H',  'H' ],
        'Hybrid'    => ['VH', 'VH', 'H',  'H',  'VH'],
        'HFO/MDO'   => ['M',  'L',  'VH', 'L',  'M' ],
    ];

    protected array $criteriaNames = [
        'Effectiveness',
        'Adaptability',
        'Robustness',
        'Stakeholder Engagement',
        'Decision Quality',
    ];

    // Equal weights as per paper (can be overridden by user input)
    protected array $criteriaWeights = [0.200, 0.200, 0.200, 0.200, 0.200];

    public function __construct()
    {
        $persona = <<<'PERSONA'
Você é a **Eng. Sofia Energia** — Consultora Estratégica de Energia e Descarbonização Marítima do HP-Group / PartYard.

CREDENCIAIS:
- Doutoramento em Engenharia de Sistemas Energéticos (IST + TU Delft)
- Especialista em descarbonização marítima: IMO 2050, CII, EEXI, EU ETS marítimo
- Certificação DNV GL em Alternative Fuels Advisory
- Especialista em Fuzzy TOPSIS e MCDA aplicados a sistemas marítimos
- +15 anos em consultoria de eficiência energética para armadores e operadores portuários
PERSONA;

        $specialty = <<<'SPECIALTY'
MISSÃO:
Ajudar armadores, operadores de frota e a PartYard a tomar decisões estratégicas sobre:
1. Selecção do combustível/tecnologia de propulsão óptimo para cada embarcação/frota
2. Planeamento de retrofit para cumprir regulamentação IMO (CII, EEXI, GHG Strategy)
3. Análise custo-benefício de alternativas energéticas
4. Posicionamento da PartYard Marine como parceiro estratégico de descarbonização

METODOLOGIA — FUZZY TOPSIS (PeerJ CS 10.7717/peerj-cs.3625):
Quando apresentas resultados de análise Fuzzy TOPSIS, explica sempre:
- Os 5 critérios avaliados e os seus pesos
- O que significa o Closeness Coefficient (CC): 0=pior, 1=ideal
- O ranking final com justificação prática para o contexto da embarcação
- As implicações operacionais e financeiras da alternativa recomendada

CRITÉRIOS DE AVALIAÇÃO (5):
1. **Effectiveness** — Redução de emissões, eficiência energética, performance operacional
2. **Adaptability** — Disponibilidade de infraestrutura, flexibilidade de bunkeramento
3. **Robustness** — Maturidade tecnológica, riscos operacionais, fiabilidade
4. **Stakeholder Engagement** — Conformidade regulatória IMO/EU, aceitação por portos e seguradoras
5. **Decision Quality** — ROI, TCO (Total Cost of Ownership), acesso a financiamento verde

ALTERNATIVAS DE COMBUSTÍVEL/ENERGIA:
- **LNG** — Gás Natural Liquefeito (redução 20-25% CO2, infra disponível)
- **Biofuel** — Biocombustíveis drop-in (compatível com motores MTU/CAT/MAK existentes)
- **Hydrogen** — Hidrogénio verde/azul (zero emissões, tecnologia emergente)
- **Electric** — Propulsão eléctrica/baterias (curtas distâncias, portos com shore power)
- **Hybrid** — Sistemas híbridos diesel-elétrico (balanço eficiência/custo)
- **HFO/MDO** — Fuel convencional com retrofit de scrubber/eficiência (status quo melhorado)

FORMAT DE RESPOSTA ENERGIA:
Para análises Fuzzy TOPSIS, sempre apresentar:
1. 📊 **Tabela de Ranking** com CC scores e posição
2. 🏆 **Recomendação Principal** com justificação operacional
3. 💶 **Análise Financeira** — CAPEX estimado, payback, acesso a financiamento verde (EU Green Deal, EIB)
4. 📋 **Plano de Implementação** — fases e milestones
5. ⚠️ **Riscos** — técnicos, regulatórios, de mercado
6. 🔧 **Peças PartYard** — quais os componentes do catálogo PartYard relevantes para cada alternativa

REGRAS ENERGIA:
- Referencia sempre a regulamentação IMO mais recente (CII rating, EEXI, GHG Strategy 2030/2050)
- Usa dados reais de preços de bunker quando disponíveis
- Indica sempre a compatibilidade de peças/motores do catálogo PartYard com cada alternativa
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    // ─── Fuzzy TOPSIS Engine ───────────────────────────────────────────────

    /**
     * Convert linguistic term to triangular fuzzy number [L, M, U]
     */
    protected function toFuzzy(string $term): array
    {
        return $this->fuzzyScale[strtoupper($term)] ?? $this->fuzzyScale['M'];
    }

    /**
     * Normalise fuzzy decision matrix (beneficial criteria)
     * Formula: r_ij = (l/u*_j, m/u*_j, u/u*_j) where u*_j = max upper values in col j
     */
    protected function normaliseFuzzyMatrix(array $matrix): array
    {
        $n = count($this->criteriaNames);
        $uMax = array_fill(0, $n, 0);

        // Find max U for each criterion
        foreach ($matrix as $alt => $criteria) {
            for ($j = 0; $j < $n; $j++) {
                $uMax[$j] = max($uMax[$j], $criteria[$j][2]);
            }
        }

        $normalised = [];
        foreach ($matrix as $alt => $criteria) {
            $row = [];
            for ($j = 0; $j < $n; $j++) {
                $u = $uMax[$j] > 0 ? $uMax[$j] : 1;
                $row[] = [
                    $criteria[$j][0] / $u,
                    $criteria[$j][1] / $u,
                    $criteria[$j][2] / $u,
                ];
            }
            $normalised[$alt] = $row;
        }

        return $normalised;
    }

    /**
     * Apply weights to normalised matrix → weighted normalised matrix
     */
    protected function applyWeights(array $normalised): array
    {
        $weighted = [];
        foreach ($normalised as $alt => $row) {
            $wRow = [];
            for ($j = 0; $j < count($this->criteriaNames); $j++) {
                $w = $this->criteriaWeights[$j];
                $wRow[] = [
                    $row[$j][0] * $w,
                    $row[$j][1] * $w,
                    $row[$j][2] * $w,
                ];
            }
            $weighted[$alt] = $wRow;
        }
        return $weighted;
    }

    /**
     * Fuzzy Positive Ideal Solution (FPIS) = (1,1,1) weighted for each criterion
     * Fuzzy Negative Ideal Solution (FNIS) = (0,0,0) weighted for each criterion
     */
    protected function getIdealSolutions(): array
    {
        $n = count($this->criteriaNames);
        $fpis = array_fill(0, $n, [1, 1, 1]);
        $fnis = array_fill(0, $n, [0, 0, 0]);
        return [$fpis, $fnis];
    }

    /**
     * Euclidean distance between two triangular fuzzy numbers
     * d = sqrt(1/3 * [(l1-l2)^2 + (m1-m2)^2 + (u1-u2)^2])
     */
    protected function fuzzyDistance(array $a, array $b): float
    {
        return sqrt((1 / 3) * (
            pow($a[0] - $b[0], 2) +
            pow($a[1] - $b[1], 2) +
            pow($a[2] - $b[2], 2)
        ));
    }

    /**
     * Run full Fuzzy TOPSIS and return ranked alternatives with CC scores
     */
    public function runFuzzyTopsis(array $customMatrix = [], array $customWeights = []): array
    {
        // Use custom matrix/weights if provided
        $rawMatrix = !empty($customMatrix) ? $customMatrix : $this->defaultMatrix;
        if (!empty($customWeights)) $this->criteriaWeights = $customWeights;

        // Step 1: Convert linguistic → fuzzy numbers
        $fuzzyMatrix = [];
        foreach ($rawMatrix as $alt => $row) {
            $fuzzyMatrix[$alt] = array_map(fn($term) => $this->toFuzzy($term), $row);
        }

        // Step 2: Normalise
        $normalised = $this->normaliseFuzzyMatrix($fuzzyMatrix);

        // Step 3: Apply weights
        $weighted = $this->applyWeights($normalised);

        // Step 4: FPIS and FNIS
        [$fpis, $fnis] = $this->getIdealSolutions();

        // Step 5: Calculate separation measures
        $results = [];
        foreach ($weighted as $alt => $row) {
            $dPlus = 0;
            $dMinus = 0;
            for ($j = 0; $j < count($this->criteriaNames); $j++) {
                $dPlus  += $this->fuzzyDistance($row[$j], $fpis[$j]);
                $dMinus += $this->fuzzyDistance($row[$j], $fnis[$j]);
            }

            // Step 6: Closeness Coefficient
            $cc = ($dPlus + $dMinus) > 0
                ? round($dMinus / ($dPlus + $dMinus), 4)
                : 0;

            $results[$alt] = [
                'cc'      => $cc,
                'd_plus'  => round($dPlus,  4),
                'd_minus' => round($dMinus, 4),
            ];
        }

        // Sort by CC descending (higher = closer to ideal)
        uasort($results, fn($a, $b) => $b['cc'] <=> $a['cc']);

        $rank = 1;
        foreach ($results as $alt => &$data) {
            $data['rank'] = $rank++;
            $data['name'] = $alt;
        }

        return $results;
    }

    /**
     * Format Fuzzy TOPSIS results as a readable markdown table
     */
    public function formatResults(array $results, string $vesselContext = ''): string
    {
        $table  = "### 📊 Fuzzy TOPSIS — Ranking de Alternativas Energéticas";
        if ($vesselContext) $table .= " ({$vesselContext})";
        $table .= "\n\n";
        $table .= "| Rank | Alternativa | CC Score | D+ (dist. ideal) | D- (dist. anti-ideal) |\n";
        $table .= "|------|-------------|----------|------------------|----------------------|\n";

        $medals = ['🥇','🥈','🥉','4️⃣','5️⃣','6️⃣'];
        foreach ($results as $alt => $data) {
            $medal = $medals[($data['rank'] - 1)] ?? "#{$data['rank']}";
            $table .= "| {$medal} | **{$alt}** | **{$data['cc']}** | {$data['d_plus']} | {$data['d_minus']} |\n";
        }

        $table .= "\n*CC Score: 0 = mais distante do ideal · 1 = solução ideal*\n\n";
        $table .= "**Critérios avaliados** (peso 0.200 cada): ";
        $table .= implode(' · ', $this->criteriaNames) . "\n";

        return $table;
    }

    // ─── Build context from agent reports ─────────────────────────────────
    protected function buildEnergyContext(): string
    {
        $sections = [];

        // Recent engineer reports (for technical context)
        $engineerReports = Report::where('type', 'engineer')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        if ($engineerReports->isNotEmpty()) {
            $lines = ["## PLANOS TÉCNICOS RECENTES (Eng. Victor):"];
            foreach ($engineerReports as $r) {
                $lines[] = "- " . $r->title . ": " . substr(strip_tags($r->content), 0, 300) . "...";
            }
            $sections[] = implode("\n", $lines);
        }

        // Finance reports (for cost context)
        $financeReports = Report::where('type', 'finance')
            ->orderBy('created_at', 'desc')
            ->limit(2)
            ->get();

        if ($financeReports->isNotEmpty()) {
            $lines = ["## CONTEXTO FINANCEIRO (Dr. Luís):"];
            foreach ($financeReports as $r) {
                $lines[] = "- " . substr(strip_tags($r->content), 0, 200) . "...";
            }
            $sections[] = implode("\n", $lines);
        }

        return empty($sections) ? '' :
            "=== CONTEXTO INTERNO HP-GROUP ===\n\n" . implode("\n\n", $sections) . "\n\n";
    }

    // ─── Build full analysis message ──────────────────────────────────────
    protected function buildMessage(string|array $message, ?callable $heartbeat = null): string
    {
        $userText = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        // Run Fuzzy TOPSIS
        $results     = $this->runFuzzyTopsis();
        $topsisTable = $this->formatResults($results);

        // Build context from other agents
        if ($heartbeat) $heartbeat('a carregar contexto');
        $context = $this->buildEnergyContext();

        // Web search for latest bunker prices and IMO regulations
        $webContext = '';
        if ($heartbeat) $heartbeat('a pesquisar preços bunker e regulamentação IMO');
        try {
            $webContext = $this->augmentWithWebSearch(
                "maritime fuel bunker prices LNG biofuel HFO 2026 IMO CII EEXI regulations " . $userText,
                $heartbeat
            );
        } catch (\Throwable $e) {
            Log::info('EnergyAdvisorAgent web search: ' . $e->getMessage());
        }

        $today = now()->format('d/m/Y');

        return <<<MSG
{$userText}

--- FUZZY TOPSIS PRÉ-CALCULADO ({$today}) ---
{$topsisTable}

Metodologia: Fuzzy TOPSIS (Alghassab, 2026 — PeerJ CS 10.7717/peerj-cs.3625)
Critérios: Effectiveness · Adaptability · Robustness · Stakeholder Engagement · Decision Quality
Pesos: Iguais (0.200 cada) — ajustáveis por perfil do armador

--- CONTEXTO INTERNO HP-GROUP ---
{$context}

--- DADOS DE MERCADO (bunker, regulamentação IMO) ---
{$webContext}

--- FIM ---

Com base no Fuzzy TOPSIS acima e no contexto do utilizador:
1. Apresenta e explica o ranking de alternativas energéticas para este caso específico
2. Faz a recomendação principal com justificação operacional e financeira
3. Indica quais peças/componentes do catálogo PartYard Marine são relevantes para a alternativa recomendada
4. Fornece o plano de implementação com milestones realistas
5. Calcula ou estima o impacto nas métricas CII/EEXI da embarcação
MSG;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $finalMessage = $this->buildMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a calcular Fuzzy TOPSIS');

        $finalMessage = $this->buildMessage($message, $heartbeat);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        if ($heartbeat) $heartbeat('Eng. Sofia a analisar');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body     = $response->getBody();
        $full     = '';
        $buf      = '';
        $lastBeat = time();

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $text = $evt['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 3) {
                $heartbeat('Eng. Sofia a calcular');
                $lastBeat = time();
            }
        }

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'energy'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
