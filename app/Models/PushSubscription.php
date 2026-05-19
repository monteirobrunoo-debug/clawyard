<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'p256dh', 'auth',
        'content_encoding', 'user_agent', 'last_sent_at',
    ];

    protected $casts = [
        'last_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convert to the array shape expected by minishlink/web-push:
     *   Subscription::create(['endpoint' => ..., 'keys' => ['p256dh' => ..., 'auth' => ...]]);
     */
    public function toWebPushArray(): array
    {
        return [
            'endpoint'         => $this->endpoint,
            'publicKey'        => $this->p256dh,
            'authToken'        => $this->auth,
            'contentEncoding'  => $this->content_encoding ?: 'aes128gcm',
        ];
    }
}
