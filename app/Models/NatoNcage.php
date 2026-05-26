<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NCAGE (NATO Commercial And Government Entity) code = fabricante.
 * Importado de Excel via `php artisan nato:import-ncage <file.xlsx>`.
 */
class NatoNcage extends Model
{
    protected $table = 'nato_ncage';

    protected $fillable = [
        'cage_code', 'company_name', 'country_code', 'country_name',
        'city', 'address', 'postcode', 'phone', 'email', 'website',
        'status', 'replaced_by', 'raw',
    ];

    protected $casts = ['raw' => 'array'];

    /**
     * Fuzzy lookup por nome de empresa via trigram (Postgres).
     * Devolve top N matches ordenados por similarity.
     */
    public static function fuzzySearch(string $query, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) return collect();

        try {
            $rows = \DB::select(
                "SELECT *, similarity(company_name, ?) AS sim
                 FROM nato_ncage
                 WHERE company_name % ?
                 ORDER BY sim DESC
                 LIMIT ?",
                [$query, $query, $limit]
            );
            return collect($rows);
        } catch (\Throwable) {
            // Fallback ILIKE para SQLite / sem pg_trgm
            return self::where('company_name', 'ILIKE', "%{$query}%")
                ->limit($limit)
                ->get();
        }
    }
}
