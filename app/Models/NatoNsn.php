<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NSN (NATO Stock Number) — XXXX-XX-XXX-XXXX.
 *   FSC (4 dígitos) — Federal Supply Class
 *   NCB (2 dígitos) — NATO country
 *   NIIN (7 dígitos) — National Item Identification
 */
class NatoNsn extends Model
{
    protected $table = 'nato_nsn';

    protected $fillable = [
        'nsn', 'fsc', 'fsc_name', 'ncb', 'niin', 'description',
        'unit_of_issue', 'manufacturer_cage', 'manufacturer_pn',
        'hazardous_material_code', 'raw',
    ];

    protected $casts = ['raw' => 'array'];

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(NatoNcage::class, 'manufacturer_cage', 'cage_code');
    }

    /**
     * Lookup exacto por NSN (formato XXXX-XX-XXX-XXXX ou 13 dígitos contíguos).
     */
    public static function findByNsn(string $nsn): ?self
    {
        $canonical = self::normalizeNsn($nsn);
        return $canonical ? self::where('nsn', $canonical)->first() : null;
    }

    /**
     * Normaliza NSN para formato canónico XXXX-XX-XXX-XXXX.
     */
    public static function normalizeNsn(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) !== 13) return null;
        return substr($digits, 0, 4) . '-' .
               substr($digits, 4, 2) . '-' .
               substr($digits, 6, 3) . '-' .
               substr($digits, 9, 4);
    }

    /**
     * Pesquisa por descrição (full-text Postgres trgm).
     */
    public static function searchByDescription(string $query, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) return collect();

        try {
            $rows = \DB::select(
                "SELECT *, similarity(description, ?) AS sim
                 FROM nato_nsn
                 WHERE description % ?
                 ORDER BY sim DESC
                 LIMIT ?",
                [$query, $query, $limit]
            );
            return collect($rows);
        } catch (\Throwable) {
            return self::where('description', 'ILIKE', "%{$query}%")
                ->limit($limit)
                ->get();
        }
    }
}
