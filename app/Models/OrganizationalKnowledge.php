<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrganizationalKnowledge — memória partilhada da empresa.
 *
 * Não confundir com `agent_memories` (per-user LTM que rolledback).
 * Estas memórias são da empresa toda — qualquer agente que use a
 * `KnowledgeSearchTool` pode encontrá-las.
 */
class OrganizationalKnowledge extends Model
{
    protected $table = 'organizational_knowledge';

    protected $fillable = [
        'knowledge_key', 'knowledge_value', 'category',
        'importance', 'source',
        'extracted_from_user_id', 'extracted_from_context',
        'tags', 'expires_at',
    ];

    protected $casts = [
        'tags'             => 'array',
        'importance'       => 'decimal:2',
        'recall_count'     => 'integer',
        'last_recalled_at' => 'datetime',
        'expires_at'       => 'datetime',
    ];

    /** Categorias canónicas — usar para filtros e validação. */
    public const CATEGORIES = [
        'supplier', 'customer', 'pricing', 'regulation',
        'process',  'product',  'preference', 'general',
    ];

    public const SOURCES = [
        'manual', 'auto-extracted', 'web-search', 'doc-import',
    ];

    public function extractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extracted_from_user_id');
    }

    /** Scope: só não-expiradas. */
    public function scopeFresh(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /** Scope: por categoria(s). */
    public function scopeOfCategory(Builder $q, string|array $cats): Builder
    {
        return $q->whereIn('category', (array) $cats);
    }

    /**
     * Scope: ordena por relevância composta (importance + recency).
     * Postgres-native; fallback portable em outros drivers.
     */
    public function scopeOrderByRelevance(Builder $q): Builder
    {
        if ($q->getConnection()->getDriverName() === 'pgsql') {
            return $q->orderByRaw(
                "(importance * (0.5 + 0.5 * EXP(-EXTRACT(EPOCH FROM (NOW() - COALESCE(last_recalled_at, created_at))) / 5184000.0))) DESC"
            );
        }
        return $q->orderByDesc('importance')->orderByDesc('last_recalled_at');
    }

    public function markRecalled(): void
    {
        $this->increment('recall_count');
        $this->forceFill(['last_recalled_at' => now()])->save();
    }
}
