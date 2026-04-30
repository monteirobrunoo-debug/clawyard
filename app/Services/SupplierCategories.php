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
}
