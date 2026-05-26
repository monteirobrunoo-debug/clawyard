<?php

namespace App\Services;

use App\Models\NatoNcage;
use App\Models\NatoNsn;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NATO Codification — pesquisa local (Postgres) NCAGE/NSN.
 *
 * Substitui Tavily lookups por trigram local em <10ms. Dados importados
 * de Excel via `php artisan nato:import` para /mnt/volume_ams3_0_ncrml_nato/.
 *
 * Política:
 *   • isAvailable() — só true se tabelas têm dados (graceful degradation).
 *   • Cache Redis 7d para lookups (NSN data é stable).
 *   • Caller (NsnLookupTool) faz fallback para Tavily se local miss.
 *
 * Custo: $0 fresh, $0 cached, $0 sempre. Velocidade: <50ms p99.
 */
class NatoCodificationService
{
    /**
     * Disponível se pelo menos uma tabela tem dados.
     * Resultado cached 5min para não fazer COUNT em cada lookup.
     */
    public function isAvailable(): bool
    {
        return Cache::remember('nato:available', 300, function () {
            try {
                return NatoNsn::query()->limit(1)->exists()
                    || NatoNcage::query()->limit(1)->exists();
            } catch (\Throwable $e) {
                Log::warning('NatoCodificationService isAvailable() failed', [
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        });
    }

    /**
     * Lookup NSN com manufacturer info (join NCAGE).
     *
     * @return array<string,mixed>|null  null se NSN inválido ou não encontrado
     */
    public function lookupNsn(string $nsn): ?array
    {
        $canonical = NatoNsn::normalizeNsn($nsn);
        if (!$canonical) return null;

        $cacheKey = 'nato:nsn:' . md5($canonical);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '__miss__' ? null : $cached;
        }

        try {
            $row = NatoNsn::where('nsn', $canonical)->first();
        } catch (\Throwable $e) {
            Log::warning('NatoCodificationService::lookupNsn DB error', [
                'nsn'   => $canonical,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$row) {
            Cache::put($cacheKey, '__miss__', now()->addHour());
            return null;
        }

        $manufacturer = null;
        if ($row->manufacturer_cage) {
            $manufacturer = NatoNcage::where('cage_code', strtoupper($row->manufacturer_cage))->first();
        }

        // Country name via NCB → nato_country_codes
        $countryName = null;
        if ($row->ncb) {
            $countryName = DB::table('nato_country_codes')
                ->where('ncb_code', $row->ncb)
                ->value('country_name');
        }

        $result = [
            'nsn'             => $row->nsn,
            'description'     => $row->description,
            'fsc'             => $row->fsc . ($row->fsc_name ? ' — ' . $row->fsc_name : ''),
            'ncb'             => $row->ncb,
            'ncb_country'     => $countryName,
            'niin'            => $row->niin,
            'unit_of_issue'   => $row->unit_of_issue,
            'manufacturer_pn' => $row->manufacturer_pn,
            'hazmat'          => $row->hazardous_material_code,
            'oem'             => $manufacturer?->company_name,
            'ncage_codes'     => $row->manufacturer_cage ? [$row->manufacturer_cage] : [],
            'manufacturer'    => $manufacturer ? [
                'cage_code'    => $manufacturer->cage_code,
                'name'         => $manufacturer->company_name,
                'country_code' => $manufacturer->country_code,
                'country'      => $manufacturer->country_name ?? $manufacturer->country_code,
                'city'         => $manufacturer->city,
                'address'      => $manufacturer->address,
                'postcode'     => $manufacturer->postcode,
                'phone'        => $manufacturer->phone,
                'email'        => $manufacturer->email,
                'website'      => $manufacturer->website,
                'status'       => $manufacturer->status,
            ] : null,
        ];

        Cache::put($cacheKey, $result, now()->addDays(7));
        return $result;
    }

    /**
     * Fuzzy search NCAGE por nome de empresa (trigram).
     * @return array<int,array<string,mixed>>
     */
    public function searchManufacturer(string $name, int $limit = 5): array
    {
        try {
            return NatoNcage::fuzzySearch($name, $limit)
                ->map(fn ($row) => is_object($row) ? (array) $row : (array) $row)
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('NatoCodificationService::searchManufacturer failed', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Lookup NCAGE por código exacto.
     */
    public function lookupCage(string $cage): ?array
    {
        $cage = strtoupper(trim($cage));
        if (mb_strlen($cage) < 3 || mb_strlen($cage) > 10) return null;

        $cacheKey = 'nato:cage:' . $cage;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '__miss__' ? null : $cached;
        }

        try {
            $row = NatoNcage::where('cage_code', $cage)->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$row) {
            Cache::put($cacheKey, '__miss__', now()->addHour());
            return null;
        }

        $result = $row->toArray();
        Cache::put($cacheKey, $result, now()->addDays(7));
        return $result;
    }

    /**
     * Search NSN por descrição (trigram pg_trgm).
     * @return array<int,array<string,mixed>>
     */
    public function searchByDescription(string $query, int $limit = 10): array
    {
        try {
            return NatoNsn::searchByDescription($query, $limit)
                ->map(fn ($row) => is_object($row) ? (array) $row : $row)
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('NatoCodificationService::searchByDescription failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Stats — totals + última importação.
     */
    public function stats(): array
    {
        try {
            return [
                'ncage_count'     => NatoNcage::count(),
                'nsn_count'       => NatoNsn::count(),
                'countries_count' => DB::table('nato_country_codes')->count(),
                'last_ncage_at'   => NatoNcage::max('updated_at'),
                'last_nsn_at'     => NatoNsn::max('updated_at'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
