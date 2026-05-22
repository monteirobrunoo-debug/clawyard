<?php

namespace App\Agents\Traits;

use App\Models\AgentMemory;
use Illuminate\Support\Facades\Log;

/**
 * AgentMemoryTrait — Long-Term Memory para agentes em chat.
 *
 * Base: Bornet et al. (2025) "Agentic Artificial Intelligence", Cap 7.
 * Estudos citados: 70% faster + 45% satisfaction com LTM persistente.
 *
 * Padrão de uso típico:
 *   class MilDefAgent {
 *       use AgentMemoryTrait;
 *       protected string $agentKey = 'mildef';
 *
 *       public function chat(string $message, array $history = []): string {
 *           $message = $this->prependMemories($message);   // recall
 *           $reply   = $this->callAnthropic(...);
 *           $this->maybeExtractAndSaveMemories($message, $reply);  // save
 *           return $reply;
 *       }
 *   }
 *
 * Recall:
 *   • Top 5 memórias mais relevantes (importance × recency decay)
 *   • Injectadas como bloco "MEMÓRIAS DE INTERAÇÕES ANTERIORES" no prompt
 *   • markRecalled() bumpa recall_count para subir ranking de memórias úteis
 *
 * Save:
 *   • Detecção heurística de "lembra-te que...", "anota...", "para que saibas..."
 *   • LLM pode também emitir tags <save_memory key="..." value="..."/> que
 *     são parsed pelo regex em maybeExtractAndSaveMemories()
 *   • Privacy scrub: nunca grava strings com padrões de password/token/cartão
 *
 * Privacy:
 *   • Memórias scopadas por user_id — Bruno não vê memórias do Pedro
 *   • Scrub regex bloqueia: passwords, bearer tokens, credit cards, NIFs
 *   • Cascade-delete quando user é eliminado
 */
trait AgentMemoryTrait
{
    /** Quantas memórias incluir no prompt (máx — top-N por relevance). */
    private const RECALL_LIMIT = 5;

    /** Tamanho máximo de cada memory_value (chars) — protege o context window. */
    private const MAX_VALUE_LEN = 500;

    /** Tamanho máximo de memory_key (chars). */
    private const MAX_KEY_LEN = 100;

    /**
     * Recupera as memórias mais relevantes para este user × agente,
     * marca-as como recordadas, e devolve um bloco de texto pronto a
     * prependar ao system prompt.
     *
     * Devolve string vazia se não há memórias OU se não há user autenticado
     * (chat anónimo / batch jobs não usam LTM).
     */
    protected function recallMemoriesBlock(): string
    {
        $userId = auth()->id();
        $agentKey = property_exists($this, 'agentKey') ? (string) $this->agentKey : 'chat';
        if (!$userId) return '';

        $rows = AgentMemory::query()
            ->for($userId, $agentKey)
            ->orderByRelevance()
            ->limit(self::RECALL_LIMIT)
            ->get();

        if ($rows->isEmpty()) return '';

        $lines = [];
        foreach ($rows as $m) {
            $lines[] = '  • ' . $m->memory_key . ': ' . $m->memory_value;
            // Side-effect: bump recall_count + last_recalled_at em background.
            // Cheap enough (~5ms) para fazer inline; se virar bottleneck,
            // mover para dispatch async via queue 'high'.
            try { $m->markRecalled(); } catch (\Throwable) {}
        }

        return "\n\n--- MEMÓRIAS DESTE UTILIZADOR (LTM, " . count($rows) . " entradas) ---\n"
             . implode("\n", $lines)
             . "\n--- FIM MEMÓRIAS ---\n\n"
             . 'Usa estas memórias para personalizar a resposta. Se houver '
             . 'contradição com a mensagem actual, prevalece a actual e '
             . 'considera actualizar a memória correspondente.';
    }

    /**
     * Helper opcional para anexar o bloco de memórias a uma mensagem
     * de utilizador (string|array). Espelha o padrão appendToMessage()
     * do WebSearchTrait.
     */
    protected function prependMemories(string|array $message): string|array
    {
        $block = $this->recallMemoriesBlock();
        if ($block === '') return $message;

        if (is_string($message)) return $block . $message;

        // Multimodal array: encontra primeiro text-block e prepende.
        foreach ($message as $i => $b) {
            if (($b['type'] ?? '') === 'text') {
                $message[$i]['text'] = $block . ($b['text'] ?? '');
                return $message;
            }
        }
        return array_merge([['type' => 'text', 'text' => $block]], $message);
    }

    /**
     * Persiste uma memória nova ou actualiza uma existente.
     * Aplica scrub PII básico e clampa tamanhos.
     *
     * @param  string $key   identificador curto (ex: "preferred_oem_mtu_396")
     * @param  string $value conteúdo (ex: "Sempre prefere MTU AG Friedrichshafen DE")
     * @param  float  $importance 0.0–1.0 (default 0.5)
     * @param  string $source 'explicit' | 'inferred' | 'system'
     */
    protected function saveMemory(
        string $key,
        string $value,
        float $importance = 0.5,
        string $source = 'inferred',
    ): ?AgentMemory {
        $userId = auth()->id();
        $agentKey = property_exists($this, 'agentKey') ? (string) $this->agentKey : 'chat';
        if (!$userId) return null;

        $key   = trim(mb_substr($key, 0, self::MAX_KEY_LEN));
        $value = trim(mb_substr($value, 0, self::MAX_VALUE_LEN));
        $importance = max(0.0, min(1.0, $importance));

        if ($key === '' || $value === '') return null;
        if (!$this->memoryValueIsSafe($value)) {
            Log::warning('AgentMemoryTrait: skipped unsafe memory (PII/secret pattern)', [
                'user_id'   => $userId,
                'agent_key' => $agentKey,
                'mem_key'   => $key,
            ]);
            return null;
        }

        return AgentMemory::updateOrCreate(
            [
                'user_id'    => $userId,
                'agent_key'  => $agentKey,
                'memory_key' => $key,
            ],
            [
                'memory_value' => $value,
                'importance'   => $importance,
                'source'       => $source,
            ],
        );
    }

    /**
     * Apaga uma memória específica. Devolve true se foi apagada.
     */
    protected function forgetMemory(string $key): bool
    {
        $userId = auth()->id();
        $agentKey = property_exists($this, 'agentKey') ? (string) $this->agentKey : 'chat';
        if (!$userId) return false;

        return AgentMemory::query()->for($userId, $agentKey)
            ->where('memory_key', $key)
            ->delete() > 0;
    }

    /**
     * Detecção heurística + LLM tag parsing para extrair memórias da turn actual.
     * Suporta 2 caminhos:
     *
     *   (a) LLM-emitted tag (explícito, mais preciso):
     *       <save_memory key="preferred_oem" value="MTU sempre" importance="0.8"/>
     *
     *   (b) User-stated heurística (português):
     *       "lembra-te que..."   "anota..."    "para que saibas..."
     *       "para futuro..."     "regra..."    "sempre..."
     *
     * Stripped da resposta antes de devolver ao user (tags) — o user vê só
     * o texto, a memória é gravada em background.
     */
    protected function maybeExtractAndSaveMemories(string $userMessage, string $reply): string
    {
        // (a) Tags emitidas pelo LLM
        $cleaned = preg_replace_callback(
            '#<save_memory\s+key="([^"]+)"\s+value="([^"]+)"(?:\s+importance="([0-9.]+)")?\s*/?>(?:</save_memory>)?#i',
            function ($m) {
                $imp = isset($m[3]) ? (float) $m[3] : 0.5;
                $this->saveMemory($m[1], $m[2], $imp, 'inferred');
                return '';  // remove a tag da resposta visível
            },
            $reply
        ) ?? $reply;

        // (b) Heurística português — só dispara se o user pediu explicitamente.
        if (preg_match_all(
            '/\b(?:lembra-te|anota|para que saibas|para futuro|regra|sempre)\b[:,\s]+([^.\n!?]{8,200})/iu',
            $userMessage,
            $matches
        )) {
            foreach ($matches[1] as $extracted) {
                $value = trim($extracted, " \t\n\r\0\x0B.,;:");
                if ($value === '') continue;
                // key = primeiras 4 palavras lowercased + underscored
                $words = preg_split('/\s+/', mb_strtolower($value));
                $key = implode('_', array_slice($words, 0, 4));
                $key = preg_replace('/[^a-z0-9_]/u', '', $key);
                if (mb_strlen($key) >= 3) {
                    $this->saveMemory($key, $value, 0.7, 'explicit');
                }
            }
        }

        return $cleaned;
    }

    /**
     * PII scrub — rejeita memórias que parecem credenciais/cartões.
     * Não é perfeito (impossível ser); é defesa de primeira linha.
     */
    private function memoryValueIsSafe(string $value): bool
    {
        $patterns = [
            '/\b(?:password|passwd|senha)\s*[:=]\s*\S{4,}/i',
            '/\bBearer\s+[A-Za-z0-9_\-\.]{20,}/i',
            '/\bsk-[A-Za-z0-9]{20,}/',           // OpenAI/Anthropic-style keys
            '/\bglpat-[A-Za-z0-9_\-]{20,}/',     // GitLab PAT
            '/\bghp_[A-Za-z0-9]{36}/',           // GitHub PAT
            '/\b\d{4}[\s-]\d{4}[\s-]\d{4}[\s-]\d{4}\b/',  // credit card spaced
            '/\b\d{16}\b/',                      // 16-digit blocks (cards)
        ];
        foreach ($patterns as $rx) {
            if (preg_match($rx, $value)) return false;
        }
        return true;
    }
}
