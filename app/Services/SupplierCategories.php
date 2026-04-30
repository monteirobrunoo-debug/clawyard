<?php

namespace App\Services;

/**
 * Static map of the H&P supplier-category taxonomy (top-level only).
 *
 * The codes match the column headers in the "Fornecedores Aprovados"
 * workbook (1..20). We intentionally don't seed sub-codes here — the
 * Matriz sheet has hundreds and they're better served by leaving the
 * raw codes ("13.15", "16.34") on the Supplier::subcategories column
 * and rendering them as plain chips in the UI.
 */
class SupplierCategories
{
    public const TOP_LEVEL = [
        '1'  => 'Ships (incl. repairs & refits)',
        '2'  => 'Shipyard installations & equipment',
        '3'  => 'Ship fittings & equipment',
        '4'  => 'Prime movers, gears & drive systems',
        '5'  => 'Auxiliary systems for prime movers',
        '6'  => 'Propulsors & manoeuvring',
        '7'  => 'Ship operation equipment',
        '8'  => 'Cargo handling & special vessel equipment',
        '9'  => 'Electrical engineering / electronics',
        '10' => 'Marine technology',
        '11' => 'Ports & port technology',
        '12' => 'Maritime services',
        '13' => 'Military, Aerospace & Defense',
        '14' => 'PartYard Systems',
        '15' => 'Industrial machinery & spares',
        '16' => 'Representatives Brands',
        '17' => 'Communication technology',
        '18' => 'Medical equipment',
        '19' => 'Transportadoras & despachantes',
        '20' => 'Material armazém',
    ];

    /**
     * Returns the human-readable label for a category code.
     * Accepts both top-level ("13") and sub-codes ("13.15") — for
     * subs we strip the suffix and look up the parent.
     */
    public static function labelFor(string $code): string
    {
        $top = explode('.', $code)[0];
        return self::TOP_LEVEL[$top] ?? $code;
    }

    /** Used by the index page filter dropdown. */
    public static function options(): array
    {
        $out = [];
        foreach (self::TOP_LEVEL as $code => $label) {
            $out[$code] = "{$code}. {$label}";
        }
        return $out;
    }

    /**
     * Map of "Representatives Brands" sub-codes (16.x) → brand name,
     * extracted from the Matriz sheet of the H&P Excel. Used for
     * brand-aware filtering on /suppliers — when the user picks "MTU"
     * (well, here 16.x) we translate to a subcategory match.
     *
     * NOTE: 16.4, 16.5, 16.6, 16.16 don't exist in the source — gaps
     * in the Excel taxonomy. We don't synthesise them.
     */
    public const BRANDS_16 = [
        '16.1'  => 'Cummins',
        '16.2'  => 'Sherwood',
        '16.3'  => 'Mercedes Benz',
        '16.7'  => 'EVAC',
        '16.8'  => 'Bosch',
        '16.9'  => 'Yamaha',
        '16.10' => 'Perkins',
        '16.11' => 'Raytheon',
        '16.12' => 'Mastervolt',
        '16.13' => 'Volvo',
        '16.14' => 'Copeland',
        '16.15' => 'UNITOR',
        '16.17' => 'Souriau',
        '16.18' => 'Caterpillar',
        '16.19' => 'Kosgra',
        '16.20' => 'AirTac',
        '16.21' => 'MAK',
        '16.22' => 'Textron',
        '16.23' => 'Lockheed Martin',
        '16.24' => 'Ruland',
        '16.25' => 'Hansbo',
        '16.26' => 'Carel',
        '16.27' => 'Mersen',
        '16.28' => 'DEFA',
        '16.29' => 'ASTROCOM',
        '16.30' => 'Honeywell Analytics',
        '16.31' => 'ABB Electric',
        '16.32' => 'Scania',
        '16.33' => 'Vickers',
        '16.34' => 'Dictator',
        '16.35' => 'Itork',
        '16.36' => 'SAFT Batteries',
        '16.37' => 'Penny & Giles',
        '16.38' => 'SAFRAN',
        '16.39' => 'FM Mattson',
        '16.40' => 'Whisper Power',
        '16.41' => 'MKN',
        '16.42' => 'Furuno',
    ];

    /** Brand options sorted by display name. Returns [code => name]. */
    public static function brandOptions(): array
    {
        $out = self::BRANDS_16;
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** Resolve a brand code to its display name (or echo the code). */
    public static function brandName(string $code): string
    {
        return self::BRANDS_16[$code] ?? $code;
    }
}
