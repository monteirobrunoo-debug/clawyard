<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TokenBudget — pool mensal de tokens Anthropic partilhado.
 *
 * 1 row por período YYYY-MM. firstOrCreate() automático pelo
 * TokenBudgetService quando o período actual ainda não existe.
 */
class TokenBudget extends Model
{
    protected $fillable = [
        'period_yyyy_mm', 'pool_eur', 'alert_at_percent', 'hard_gate_at_percent',
        'notified_at_80', 'notified_at_100',
    ];

    protected $casts = [
        'pool_eur'             => 'decimal:2',
        'alert_at_percent'     => 'integer',
        'hard_gate_at_percent' => 'integer',
        'notified_at_80'       => 'datetime',
        'notified_at_100'      => 'datetime',
    ];

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }
}
