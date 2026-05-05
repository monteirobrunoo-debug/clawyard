<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderServiceAnalysis extends Model
{
    protected $fillable = [
        'tender_id', 'status',
        'agents_consulted', 'sections', 'executive_summary',
        'total_cost_usd',
        'generated_by_user_id', 'generated_at',
    ];

    protected $casts = [
        'agents_consulted' => 'array',
        'sections'         => 'array',
        'total_cost_usd'   => 'decimal:4',
        'generated_at'     => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function isFresh(int $hours = 24): bool
    {
        return $this->generated_at && $this->generated_at->diffInHours(now()) < $hours;
    }
}
