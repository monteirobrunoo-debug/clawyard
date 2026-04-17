<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentShareAccessLog extends Model
{
    protected $fillable = [
        'agent_share_id', 'email', 'session_id', 'fingerprint',
        'ip', 'country', 'user_agent', 'event', 'status', 'note',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(AgentShare::class, 'agent_share_id');
    }
}
