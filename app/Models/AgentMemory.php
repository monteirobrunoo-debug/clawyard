<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AgentMemory — Long-Term Memory (LTM) record para um agente.
 *
 * Base: Bornet et al. (2025), "Agentic Artificial Intelligence", Cap 7.
 *
 * Scope: (user_id, agent_key, memory_key) é único — a tabela é um
 * key-value store por (utilizador, persona do agente). Cada agente
 * tem o seu próprio caderno de notas por user.
 *
 * Não usar para PII sensível (cartões, passwords, tokens). O Trait
 * AgentMemoryTrait::saveMemory faz scrub mas não é à prova de bala.
 */
class AgentMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'agent_key', 'memory_key', 'memory_value',
        'importance', 'recall_count', 'last_recalled_at', 'source',
    ];

    protected $casts = [
        'importance'       => 'decimal:2',
        'recall_count'     => 'integer',
        'last_recalled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Scope: memórias para um agente de um user específico. */
    public function scopeFor(Builder $q, int $userId, string $agentKey): Builder
    {
        return $q->where('user_id', $userId)->where('agent_key', $agentKey);
    }

    /**
     * Scope: ordena por relevância composta (importance + recency).
     * recency = decay exponencial sobre last_recalled_at (mais recente = mais peso).
     * Implementação Postgres-native; em SQLite (test) cai num ORDER BY mais simples.
     */
    public function scopeOrderByRelevance(Builder $q): Builder
    {
        $driver = $q->getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            // Postgres: importance × decay(days_since_recall)
            // Memória recém-recordada (last_recalled_at = now) → peso ~1.
            // Memória nunca recordada (NULL) ou há 30 dias → peso ~0.4.
            return $q->orderByRaw(
                "(importance * (0.4 + 0.6 * EXP(-EXTRACT(EPOCH FROM (NOW() - COALESCE(last_recalled_at, created_at))) / 2592000.0))) DESC"
            );
        }

        // Fallback portable: importance desc, then last_recalled_at desc.
        return $q->orderByDesc('importance')->orderByDesc('last_recalled_at');
    }

    /**
     * Marca esta memória como "recordada agora" — usado pelo recall path.
     * Bump recall_count + last_recalled_at. Side-effect deliberado para
     * que memórias usadas frequentemente subam no ranking.
     */
    public function markRecalled(): void
    {
        $this->increment('recall_count');
        $this->forceFill(['last_recalled_at' => now()])->save();
    }
}
