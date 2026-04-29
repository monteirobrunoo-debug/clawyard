<?php

namespace App\Models;

use App\Services\AgentCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per autonomous purchase decision an agent makes. Whole
 * lifecycle (committee → search → purchase → design → STL) lives on
 * the same row — easier auditing than splitting across normalised
 * tables, and the JSON columns carry the heavy data anyway.
 */
class PartOrder extends Model
{
    public const STATUS_COMMITTEE  = 'committee';   // multi-agent deliberation
    public const STATUS_SEARCHING  = 'searching';   // buyer doing web search
    public const STATUS_PURCHASED  = 'purchased';   // picked one, debited
    public const STATUS_DESIGNING  = 'designing';   // generating CAD
    public const STATUS_STL_READY  = 'stl_ready';   // .stl on disk
    public const STATUS_CNC_QUEUED = 'cnc_queued';  // sent to CNC (future)
    public const STATUS_COMPLETED  = 'completed';   // physical delivered
    public const STATUS_CANCELLED  = 'cancelled';   // aborted somewhere

    protected $fillable = [
        'agent_key',
        'name',
        'description',
        'source_url',
        'source_image_url',
        'cost_usd',
        'status',
        'search_query',
        'search_candidates',
        'committee_log',
        'design_scad',
        'stl_path',
        'designed_at',
        'notes',
    ];

    /**
     * Mirrors the DB default so newly-created models have the right
     * status in memory without needing a `->fresh()` reload.
     */
    protected $attributes = [
        'status' => self::STATUS_COMMITTEE,
    ];

    protected function casts(): array
    {
        return [
            'cost_usd'           => 'decimal:4',
            'search_candidates'  => 'array',
            'committee_log'      => 'array',
            'designed_at'        => 'datetime',
        ];
    }

    /** The wallet this order debited from (or will debit). */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AgentWallet::class, 'agent_key', 'agent_key');
    }

    /** Display metadata for the buyer agent. */
    public function agentMeta(): ?array
    {
        return AgentCatalog::find($this->agent_key);
    }

    /** Append a new committee step to the deliberation log. */
    public function appendCommittee(string $agentKey, string $role, string $text): void
    {
        $log = $this->committee_log ?? [];
        $log[] = [
            'agent_key' => $agentKey,
            'role'      => $role,
            'text'      => $text,
            'at'        => now()->toIso8601String(),
        ];
        $this->committee_log = $log;
        $this->save();
    }

    /**
     * Visual-only: a pretty status label for the UI.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_COMMITTEE  => '🗣️ Em deliberação',
            self::STATUS_SEARCHING  => '🔍 A pesquisar na net',
            self::STATUS_PURCHASED  => '🛒 Comprado',
            self::STATUS_DESIGNING  => '✏️ A desenhar CAD',
            self::STATUS_STL_READY  => '📦 STL pronto',
            self::STATUS_CNC_QUEUED => '⚙️ Enviado para CNC',
            self::STATUS_COMPLETED  => '✅ Entregue',
            self::STATUS_CANCELLED  => '❌ Cancelado',
            default                  => $this->status,
        };
    }

    /**
     * URL to download the STL file. Returns null until the design
     * phase completes successfully.
     */
    public function stlDownloadUrl(): ?string
    {
        if (!$this->stl_path) return null;
        return route('parts.stl', $this->id);
    }
}
