<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single procurement opportunity — one row in the unified tenders table.
 *
 * LIFECYCLE
 * ---------
 *   1. Imported from source (NSPA Excel, SAM.gov API, NCIA, …) → status=pending, unassigned
 *   2. Super-user (admin/manager) assigns to a TenderCollaborator
 *   3. Collaborator works it → creates SAP opportunity, updates status
 *   4. Terminates in submetido / ganho / perdido / cancelado / nao_tratar
 *
 * The daily digest (morning + end-of-afternoon) prompts the assigned user
 * to push the record forward whenever `needsActionPrompt()` is true.
 */
class Tender extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source', 'reference', 'title', 'type', 'purchasing_org',
        'status', 'priority',
        'assigned_collaborator_id', 'assigned_at', 'assigned_by_user_id',
        'deadline_at', 'source_modified_at',
        'sap_opportunity_number',
        'offer_value', 'currency', 'time_spent_hours',
        'notes', 'result',
        'raw_metadata', 'last_import_id', 'last_digest_sent_at',
        'deadline_alert_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_at'          => 'datetime',
            'source_modified_at'   => 'datetime',
            'assigned_at'          => 'datetime',
            'last_digest_sent_at'  => 'datetime',
            'deadline_alert_sent_at' => 'datetime',
            'offer_value'          => 'decimal:2',
            'time_spent_hours'     => 'decimal:2',
            'raw_metadata'         => 'array',
        ];
    }

    // ── Status vocabulary ────────────────────────────────────────────────
    public const STATUS_PENDING       = 'pending';        // new import, no status yet
    public const STATUS_EM_TRATAMENTO = 'em_tratamento';
    public const STATUS_SUBMETIDO     = 'submetido';
    public const STATUS_AVALIACAO     = 'avaliacao';
    public const STATUS_CANCELADO     = 'cancelado';
    public const STATUS_NAO_TRATAR    = 'nao_tratar';
    public const STATUS_GANHO         = 'ganho';
    public const STATUS_PERDIDO       = 'perdido';

    /** Statuses where the file is still live and the digest should keep nudging. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_EM_TRATAMENTO,
        self::STATUS_SUBMETIDO,
        self::STATUS_AVALIACAO,
    ];

    /** Statuses that terminate the workflow — no more daily emails. */
    public const TERMINAL_STATUSES = [
        self::STATUS_CANCELADO,
        self::STATUS_NAO_TRATAR,
        self::STATUS_GANHO,
        self::STATUS_PERDIDO,
    ];

    /** Supported source keys — match TenderImport.source. */
    public const SOURCES = [
        'nspa', 'nato', 'sam_gov', 'ncia',
        'acingov', 'vortal', 'ungm', 'unido', 'other',
    ];

    /**
     * Map arbitrary source-language status strings to our canonical enum.
     * Everything unknown collapses to `pending` so the dashboard surfaces
     * "something needs human attention" instead of silently dropping it.
     */
    public static function normaliseStatus(?string $raw): string
    {
        if (!$raw) return self::STATUS_PENDING;
        $key = strtolower(\Illuminate\Support\Str::ascii(trim($raw)));
        return match ($key) {
            'em tratamento', 'em_tratamento', 'in progress'          => self::STATUS_EM_TRATAMENTO,
            'submetido', 'submitted'                                 => self::STATUS_SUBMETIDO,
            'avaliacao', 'avaliação', 'evaluation'                   => self::STATUS_AVALIACAO,
            'cancelado', 'cancelled', 'canceled'                     => self::STATUS_CANCELADO,
            'nao tratar', 'não tratar', 'nao_tratar', 'do not treat' => self::STATUS_NAO_TRATAR,
            'ganho', 'won'                                           => self::STATUS_GANHO,
            'perdido', 'lost'                                        => self::STATUS_PERDIDO,
            default                                                  => self::STATUS_PENDING,
        };
    }

    // ── Relations ────────────────────────────────────────────────────────
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(TenderCollaborator::class, 'assigned_collaborator_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(TenderImport::class, 'last_import_id');
    }

    /**
     * Hard cap for "overdue" — anything past this window is considered
     * dead/expired rather than actionable. User rule: 15 days.
     */
    public const OVERDUE_WINDOW_DAYS = 15;

    // ── Scopes ───────────────────────────────────────────────────────────
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * "Activos" as the dashboard user reads them: in an active status AND
     * the deadline hasn't passed yet (or there's no deadline at all).
     * Overdue tenders are intentionally excluded — they live in their own
     * bucket for separate triage.
     */
    public function scopeActiveInProgress(Builder $q): Builder
    {
        return $q->active()->where(function ($w) {
            $w->whereNull('deadline_at')->orWhere('deadline_at', '>=', now());
        });
    }

    /**
     * Overdue but still rescuable — past deadline by 0..OVERDUE_WINDOW_DAYS.
     * Older than that is considered expired/abandoned and excluded here.
     */
    public function scopeOverdue(Builder $q): Builder
    {
        $now    = now();
        $cutoff = $now->copy()->subDays(self::OVERDUE_WINDOW_DAYS);
        return $q->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', $now)
            ->where('deadline_at', '>=', $cutoff);
    }

    /**
     * Expired — overdue by MORE than OVERDUE_WINDOW_DAYS. These are surfaced
     * in the "Expirado" bucket so someone can bulk-close them.
     */
    public function scopeExpired(Builder $q): Builder
    {
        return $q->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now()->copy()->subDays(self::OVERDUE_WINDOW_DAYS));
    }

    public function scopeForCollaborator(Builder $q, int $collaboratorId): Builder
    {
        return $q->where('assigned_collaborator_id', $collaboratorId);
    }

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        // assigned to the collaborator who is linked to this user
        return $q->whereHas('collaborator', fn($c) => $c->where('user_id', $userId));
    }

    public function scopeUrgent(Builder $q, int $daysThreshold = 7): Builder
    {
        return $q->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now()->addDays($daysThreshold));
    }

    public function scopeNeedingSapOpportunity(Builder $q): Builder
    {
        return $q->active()
            ->whereNotNull('assigned_collaborator_id')
            ->where(fn($w) => $w->whereNull('sap_opportunity_number')->orWhere('sap_opportunity_number', ''));
    }

    // ── Timezone accessors ───────────────────────────────────────────────
    /**
     * User explicitly asked for dual-timezone display (Lisbon + Luxembourg).
     * Storage stays UTC; these accessors format for the UI.
     */
    public function getDeadlineLisbonAttribute(): ?Carbon
    {
        return $this->deadline_at?->copy()->setTimezone('Europe/Lisbon');
    }

    public function getDeadlineLuxembourgAttribute(): ?Carbon
    {
        return $this->deadline_at?->copy()->setTimezone('Europe/Luxembourg');
    }

    /** Negative = overdue. Null if no deadline. */
    public function getDaysToDeadlineAttribute(): ?int
    {
        if (!$this->deadline_at) return null;
        return (int) floor(now()->diffInHours($this->deadline_at, false) / 24);
    }

    public function getUrgencyBucketAttribute(): string
    {
        $d = $this->days_to_deadline;
        if ($d === null)                                return 'unknown';
        // Overdue caps at OVERDUE_WINDOW_DAYS — anything older is "expired"
        // (abandoned / needs bulk-close, not actionable).
        if ($d < -self::OVERDUE_WINDOW_DAYS)            return 'expired';
        if ($d < 0)                                     return 'overdue';
        if ($d <= 3)                                    return 'critical';
        if ($d <= 7)                                    return 'urgent';
        if ($d <= 14)                                   return 'soon';
        return 'normal';
    }

    // ── Action prompts for the daily digest ──────────────────────────────
    /** True when the row should appear in today's email. */
    public function needsActionPrompt(): bool
    {
        if (!in_array($this->status, self::ACTIVE_STATUSES, true)) return false;
        // Must be assigned to appear in a collaborator's personal digest
        if (!$this->assigned_collaborator_id)                       return true; // super-user still sees it
        // Still active → always prompt (email includes countdown)
        return true;
    }

    /** @return list<string> Bullet-point prompts shown in the email for this row. */
    public function digestPrompts(): array
    {
        $prompts = [];

        if ($this->status === self::STATUS_PENDING) {
            $prompts[] = 'Ainda sem estado — marcar como Em Tratamento / Não Tratar / etc.';
        }

        if (in_array($this->status, [self::STATUS_EM_TRATAMENTO, self::STATUS_PENDING], true)
            && empty($this->sap_opportunity_number)) {
            $prompts[] = 'Ainda sem nº de Oportunidade SAP — criar no SAP B1 e registar aqui.';
        }

        if ($this->status === self::STATUS_NAO_TRATAR && empty($this->notes)) {
            $prompts[] = 'Indicar razão para "Não Tratar" (notas).';
        }

        $days = $this->days_to_deadline;
        if ($days !== null && $days < 0) {
            $prompts[] = 'Deadline ultrapassado — actualizar resultado (Ganho / Perdido / Cancelado).';
        } elseif ($days !== null && $days <= 3) {
            $prompts[] = "Deadline em {$days} dia(s) — prioridade máxima.";
        }

        return $prompts;
    }
}
