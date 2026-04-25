<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per admin action against a User. See migration for the
 * design rationale.
 *
 * Convenience helpers (`record*`) keep callers from having to know
 * the event_type vocabulary — pass before/after and a short reason.
 *
 * The model is append-only: there's no update path. If a payload
 * needs correction, write a new event referring back via payload
 * instead of mutating history.
 */
class UserAdminEvent extends Model
{
    public const UPDATED_AT = null;   // append-only

    protected $fillable = [
        'target_user_id',
        'actor_user_id',
        'event_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    // ── Event-type vocabulary ─────────────────────────────────────────────
    public const TYPE_ROLE_CHANGE = 'role_change';
    public const TYPE_DEACTIVATE  = 'deactivate';
    public const TYPE_REACTIVATE  = 'reactivate';
    public const TYPE_DELETE      = 'delete';
    public const TYPE_CREATE      = 'create';

    // ── Relations ─────────────────────────────────────────────────────────
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ── Convenience writers ───────────────────────────────────────────────
    public static function recordRoleChange(int $targetId, ?int $actorId, string $from, string $to): self
    {
        return self::create([
            'target_user_id' => $targetId,
            'actor_user_id'  => $actorId,
            'event_type'     => self::TYPE_ROLE_CHANGE,
            'payload'        => ['from' => $from, 'to' => $to],
        ]);
    }

    public static function recordActivation(int $targetId, ?int $actorId, bool $newActive): self
    {
        return self::create([
            'target_user_id' => $targetId,
            'actor_user_id'  => $actorId,
            'event_type'     => $newActive ? self::TYPE_REACTIVATE : self::TYPE_DEACTIVATE,
            'payload'        => ['is_active' => $newActive],
        ]);
    }

    public static function recordDelete(int $targetId, ?int $actorId, array $snapshot = []): self
    {
        return self::create([
            'target_user_id' => $targetId,
            'actor_user_id'  => $actorId,
            'event_type'     => self::TYPE_DELETE,
            'payload'        => $snapshot,
        ]);
    }

    public static function recordCreate(int $targetId, ?int $actorId, array $snapshot = []): self
    {
        return self::create([
            'target_user_id' => $targetId,
            'actor_user_id'  => $actorId,
            'event_type'     => self::TYPE_CREATE,
            'payload'        => $snapshot,
        ]);
    }
}
