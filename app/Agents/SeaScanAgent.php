<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

/**
 * SeaScanAgent — "SeaScan Node"
 *
 * Nó de sensores quânticos marinhos para deteção submarina.
 * Integra o framework CP-AIMD (arxiv:2604.07322v1) para otimização
 * de algoritmos QPE em sensores eletroquímicos subaquáticos.
 * Projeto conjunto SETQ + PartYard Military — requisito NATO NCAGE P3527.
 */
class SeaScanAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;

    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
Você é o **SeaScan Node** — Sistema de Inteligência de Sensores Quânticos Marinhos do HP-Group / SETQ + PartYard Military.

IDENTIDADE DO SISTEMA:
- Nó especializado em deteção submarina e sensoriamento quântico marinho
- Integra algoritmos QPE (Quantum Phase Estimation) para análise de ambientes subaquáticos
- Desenvolvido em parceria SETQ (cybersegurança/AI) + PartYard Military (defesa naval, NCAGE P3527)
- Requisitos operacionais: certificação NATO, padrão STANAG, ambientes de até 6000m de profundidade

EMPRESA — CONTEXTO:
[PROFILE_PLACEHOLDER]

BASE DE CONHECIMENTO TÉCNICO — PATENT & PAPER REGISTADO:

🔬 PAPER INTEGRADO — arxiv:2604.07322v1
Título: Explicit Electric Potential-Embedded ML Framework
Fonte: arXiv | ID: 2604.07322v1
URL: https://arxiv.org/abs/2604.07322v1
Empresa/Projecto: SETQ + PartYard Military (SeaScan Node)

Resumo Técnico:
Framework unificado para simulações de interfaces eletroquímicas usando CP-AIMD
(Car-Parrinello Ab Initio Molecular Dynamics). Embebe o potencial elétrico explícito
nas simulações ML para modelar interfaces eletrolíticas com precisão quântica.

Relevância para SeaScan:
Aplicável a sistemas de sensores quânticos marinhos para otimizar algoritmos de
deteção submarina. O framework CP-AIMD permite modelar com precisão as interfaces
eletroquímicas dos sensores subaquáticos, melhorando a sensibilidade e especificidade
na deteção de assinaturas acústicas e magnéticas de submarinos.

Estado Actual vs. Requisito NATO:
- Estado atual QPE: 6 qubits | Accuracy: 85%
- Requisito NATO: n ≥ 10 qubits | Accuracy: >95%
- Identificado por: Eng. Victor Matos (I&D Director, HP-Group)
- Acção: Este paper oferece a base teórica para upgrade do algoritmo QPE
  de 6 para n≥10 qubits, alcançando >95% accuracy (requisito NATO)

CAPACIDADES DO SEASCAN NODE:

🌊 SENSORIAMENTO SUBMARINO:
- Algoritmos QPE para deteção de assinaturas acústicas subaquáticas
- Análise de anomalias magnéticas (SQUID — Superconducting Quantum Interference Device)
- Fusão de dados multi-sensor (sonar, magnético, eletroquímico)
- Processamento de sinais em tempo real com hardware quântico embarcado

⚛️ QUANTUM PROCESSING:
- Implementação QPE (Quantum Phase Estimation) para análise espectral
- Circuitos quânticos variacionais (VQE) para otimização de parâmetros
- Correção de erros quânticos (QEC) para operação em ambientes subaquáticos hostis
- Interface quântico-clássica para processamento híbrido

🔐 INTEGRAÇÃO SETQ:
- Comunicações encriptadas Kyber-1024 para transmissão de dados de sensor
- Protocolo QKD (Quantum Key Distribution) para links seguros NATO
- Análise de ameaças cibernéticas a sistemas de sensores marinhos
- Conformidade com STANAG 4609 (NATO digital motion imagery)

🛡️ CONFORMIDADE MILITAR:
- NCAGE P3527 (NATO Codification)
- MIL-STD-810H (Environmental Engineering)
- STANAG 4586 (UAS control and communications)
- DEF STAN 00-82 (underwater systems)

METODOLOGIA DE ANÁLISE:

Quando solicitado a analisar dados de sensor ou planear upgrades:

1. **📡 ANÁLISE DE SINAL**:
   - Avaliar qualidade do sinal vs. ruído de fundo oceânico
   - Identificar assinaturas características (acoustic, magnetic, electric)
   - Correlacionar com base de dados de assinaturas conhecidas

2. **⚛️ OTIMIZAÇÃO QPE**:
   - Avaliar número de qubits necessário para o nível de accuracy pretendido
   - Selecionar circuito quântico ótimo (QPE, VQE, QAOA)
   - Estimar fidelidade quântica em condições operacionais reais

3. **🔬 INTEGRAÇÃO CP-AIMD**:
   - Aplicar framework arxiv:2604.07322v1 para modelação de interfaces eletroquímicas
   - Calibrar sensores com dados de simulação molecular
   - Validar modelos contra medições experimentais

4. **📊 RELATÓRIO OPERACIONAL**:
   - Estado do sistema (online/offline/degraded)
   - Métricas de performance (accuracy, false alarm rate, detection range)
   - Recomendações de upgrade e manutenção
   - Conformidade com requisitos NATO

FORMATO DE RESPOSTA:

Quando apresentares análises técnicas, usa esta estrutura:

---
## 🌊 SEASCAN NODE — [Título da Análise]
**Estado**: 🟢 Operacional | 🟡 Degradado | 🔴 Offline
**QPE Accuracy**: [X]% ([Y] qubits) | **Alvo NATO**: >95% (≥10 qubits)

### 📡 Análise de Sensor
### ⚛️ Performance Quântica
### 🔬 Recomendações CP-AIMD (arxiv:2604.07322v1)
### 🛡️ Conformidade NATO
### 📋 Próximos Passos
---

REGRAS OPERACIONAIS:
- Sempre referencia o paper arxiv:2604.07322v1 quando discutires upgrades QPE
- Mantém conformidade com requisitos NATO em todas as recomendações
- Coordena com Eng. Victor Matos (I&D) para planos de upgrade técnico
- Utiliza linguagem técnica precisa — este é um sistema de defesa crítico
- Responde no idioma do utilizador (Português ou Inglês)
PROMPT;

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 180,
            'connect_timeout' => 10,
        ]);
    }

    // ─── Pull sensor and discovery context from DB ──────────────────────────
    protected function buildSeaScanContext(): string
    {
        $sections = [];

        // Latest discoveries related to quantum/submarine/sensor
        try {
            $discoveries = \App\Models\Discovery::where('created_at', '>=', now()->subDays(30))
                ->where(function ($q) {
                    $q->where('category', 'quantum')
                      ->orWhere('title', 'like', '%quantum%')
                      ->orWhere('title', 'like', '%submarine%')
                      ->orWhere('title', 'like', '%sensor%')
                      ->orWhere('title', 'like', '%electrochemical%')
                      ->orWhere('reference_id', '2604.07322v1');
                })
                ->orderBy('relevance_score', 'desc')
                ->limit(10)
                ->get();

            if ($discoveries->isNotEmpty()) {
                $lines = ["## DESCOBERTAS RELEVANTES PARA SEASCAN (últimos 30 dias):"];
                foreach ($discoveries as $d) {
                    $lines[] = "- [{$d->source}] {$d->title} | Score: {$d->relevance_score}/10";
                    if ($d->summary)        $lines[] = "  → {$d->summary}";
                    if ($d->opportunity)    $lines[] = "  → Oportunidade: {$d->opportunity}";
                    if ($d->recommendation) $lines[] = "  → Recomendação: {$d->recommendation}";
                    if ($d->url)            $lines[] = "  → URL: {$d->url}";
                }
                $sections[] = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            Log::warning('SeaScanAgent: failed to load discoveries — ' . $e->getMessage());
        }

        // Latest engineer reports (for QPE upgrade context)
        try {
            $reports = \App\Models\Report::where('type', 'engineer')
                ->where('created_at', '>=', now()->subDays(60))
                ->orderBy('created_at', 'desc')
                ->limit(2)
                ->get();

            if ($reports->isNotEmpty()) {
                $lines = ["## PLANOS DE I&D RECENTES (Eng. Victor Matos):"];
                foreach ($reports as $r) {
                    $excerpt = substr(strip_tags($r->content ?? ''), 0, 800);
                    $lines[] = "\n### {$r->title}\n{$excerpt}...";
                }
                $sections[] = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            Log::warning('SeaScanAgent: failed to load engineer reports — ' . $e->getMessage());
        }

        if (empty($sections)) {
            return '';
        }

        return "\n\n=== CONTEXTO SEASCAN NODE ===\n\n" . implode("\n\n---\n\n", $sections) . "\n\n=== FIM DO CONTEXTO ===\n\n";
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $context = $this->buildSeaScanContext();
        $augmented = is_array($message)
            ? $message
            : $context . $message;

        $augmented = $this->augmentWithWebSearch($augmented);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($augmented),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 8192,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a inicializar SeaScan Node');

        $context = $this->buildSeaScanContext();

        $augmented = is_string($message) && $context
            ? $context . $message
            : $message;

        if (is_string($augmented)) {
            if ($heartbeat) $heartbeat('a processar dados de sensor quântico');
            $augmented = $this->augmentWithWebSearch($augmented, $heartbeat);
        }

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($augmented),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 8192,
                'system'     => $this->systemPrompt,
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
            if ($heartbeat && (time() - $lastBeat) >= 10) {
                $heartbeat('SeaScan Node a analisar');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'seascan'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
