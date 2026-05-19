<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderSavedView extends Model
{
    protected $fillable = ['user_id', 'name', 'filters', 'sort_order'];

    protected $casts = [
        'filters'    => 'array',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Constrói uma query string canonical (?source=…&status=…) a partir
     * dos filtros guardados — usada para gerar o href do chip no header.
     * Omite chaves null/vazias para o URL ficar limpo.
     */
    public function toQueryString(): string
    {
        $params = [];
        foreach ((array) $this->filters as $k => $v) {
            if ($v === null || $v === '' || $v === []) continue;
            $params[$k] = $v;
        }
        return $params ? '?' . http_build_query($params) : '';
    }
}
