<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail. See migration `create_audit_logs_table` for context.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'user_id', 'action', 'resource_type', 'resource_id',
        'ip', 'user_agent', 'payload',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience: record an audit event from anywhere.
     *
     * Auto-fills user_id from Auth, ip+ua from current request. Caller
     * needs only the semantic fields. Failures are swallowed — auditing
     * should NEVER break the primary action.
     */
    public static function record(string $action, $resource = null, array $payload = []): void
    {
        try {
            static::create([
                'user_id'       => auth()->id(),
                'action'        => $action,
                'resource_type' => $resource ? class_basename($resource) : null,
                'resource_id'   => is_object($resource) ? ($resource->id ?? null) : null,
                'ip'            => request()->ip(),
                'user_agent'    => substr((string) request()->userAgent(), 0, 255),
                'payload'       => $payload ?: null,
            ]);
        } catch (\Throwable $e) {
            \Log::warning("AuditLog::record failed for {$action}: " . $e->getMessage());
        }
    }
}
