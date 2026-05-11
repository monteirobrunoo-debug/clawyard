<?php

namespace App\Data;

/**
 * FedEx / TNT country → zone mapping for PartYard shipping quotes.
 *
 * Source: TNT (a FedEx Express brand) public tariff "Tarifa Internacional
 * 2025 Portugal" + "Tarifa Nacional 2026 Portugal" (TNT é parte do grupo
 * FedEx desde 2016 — em PT a marca TNT continua activa em FedEx Express).
 *
 * Zones 1-9 cover the same ~190 countries that UPS uses, but TNT's zone
 * numbers DO NOT match UPS zones — keep the data sets independent.
 *
 * For Spain we have Zone 1 (major peninsular cities) + Zone 2 (rest);
 * the spec is a list of cities, so we map both ES values and let the
 * caller hint which sub-zone with `dest_postal` if available.
 *
 * For Portugal domestic, "zones" are:
 *   pt1   — continental (default, Zone 1)
 *   pt2   — códigos postais 5000-5999, 6050, 7000-8999 (Zone 2)
 *   pt_m  — Madeira
 *   pt_a  — Açores
 *
 * Some destinations have separate export/import zones (HK, JP, CN, SG,
 * TW, KR, MY, PH, ID, VN, TH). For send_* we use the export entry;
 * for receive_* the import entry. Where only one value exists in the
 * PDF, both keys point to the same number.
 */
class FedExZones
{
    /**
     * ISO-2 → 'zone_export' (PT→country) and 'zone_import' (country→PT).
     *
     * Both keys present even when identical so callers don't have to
     * test which side they're shipping.
     *
     * Pulled directly from the "Serviços e Tempos de Trânsito" tables
     * (pages 9-13 of TNT Tarifa Internacional 2025).
     */
    public const ZONE_MAP = [
        // ── Europa ─────────────────────────────────────────────────
        'AL' => ['zone_export' => 5, 'zone_import' => 5],   // Albânia
        'AD' => ['zone_export' => 4, 'zone_import' => 4],   // Andorra
        'AT' => ['zone_export' => 3, 'zone_import' => 3],   // Áustria
        'BY' => ['zone_export' => 5, 'zone_import' => 5],   // Bielo-Rússia
        'BE' => ['zone_export' => 2, 'zone_import' => 2],   // Bélgica
        'BA' => ['zone_export' => 5, 'zone_import' => 5],   // Bósnia
        'BG' => ['zone_export' => 4, 'zone_import' => 4],   // Bulgária
        'CY' => ['zone_export' => 5, 'zone_import' => 5],   // Chipre
        'HR' => ['zone_export' => 4, 'zone_import' => 4],   // Croácia
        'DK' => ['zone_export' => 3, 'zone_import' => 3],   // Dinamarca
        'SK' => ['zone_export' => 4, 'zone_import' => 4],   // Eslováquia
        'SI' => ['zone_export' => 4, 'zone_import' => 4],   // Eslovénia
        'ES' => ['zone_export' => 1, 'zone_import' => 1, 'zone_export_alt' => 2, 'zone_import_alt' => 2],  // Espanha (Z1 = major cities, Z2 = rest)
        'EE' => ['zone_export' => 4, 'zone_import' => 4],   // Estónia
        'FI' => ['zone_export' => 4, 'zone_import' => 4],   // Finlândia
        'FR' => ['zone_export' => 3, 'zone_import' => 3],   // França
        'DE' => ['zone_export' => 3, 'zone_import' => 3],   // Alemanha
        'GI' => ['zone_export' => 4, 'zone_import' => 4],   // Gibraltar
        'GR' => ['zone_export' => 4, 'zone_import' => 4],   // Grécia
        'HU' => ['zone_export' => 4, 'zone_import' => 4],   // Hungria
        'IE' => ['zone_export' => 3, 'zone_import' => 3],   // Irlanda
        'IS' => ['zone_export' => 4, 'zone_import' => 4],   // Islândia
        'IT' => ['zone_export' => 3, 'zone_import' => 3],   // Itália
        'XK' => ['zone_export' => 5, 'zone_import' => 5],   // Kosovo
        'LV' => ['zone_export' => 4, 'zone_import' => 4],   // Letónia
        'LI' => ['zone_export' => 4, 'zone_import' => 4],   // Liechtenstein
        'LT' => ['zone_export' => 4, 'zone_import' => 4],   // Lituânia
        'LU' => ['zone_export' => 2, 'zone_import' => 2],   // Luxemburgo
        'MK' => ['zone_export' => 5, 'zone_import' => 5],   // Macedónia do Norte
        'MT' => ['zone_export' => 5, 'zone_import' => 5],   // Malta
        'MD' => ['zone_export' => 5, 'zone_import' => 5],   // Moldávia
        'MC' => ['zone_export' => 3, 'zone_import' => 3],   // Mónaco
        'ME' => ['zone_export' => 5, 'zone_import' => 5],   // Montenegro
        'NO' => ['zone_export' => 4, 'zone_import' => 4],   // Noruega
        'NL' => ['zone_export' => 2, 'zone_import' => 2],   // Países Baixos
        'PL' => ['zone_export' => 3, 'zone_import' => 3],   // Polónia
        'GB' => ['zone_export' => 3, 'zone_import' => 3],   // Reino Unido
        'CZ' => ['zone_export' => 4, 'zone_import' => 4],   // Rep. Checa
        'RO' => ['zone_export' => 4, 'zone_import' => 4],   // Roménia
        'RU' => ['zone_export' => 5, 'zone_import' => 5],   // Rússia
        'SM' => ['zone_export' => 4, 'zone_import' => 4],   // San Marino
        'RS' => ['zone_export' => 5, 'zone_import' => 5],   // Sérvia
        'SE' => ['zone_export' => 3, 'zone_import' => 3],   // Suécia
        'CH' => ['zone_export' => 4, 'zone_import' => 4],   // Suíça
        'TR' => ['zone_export' => 4, 'zone_import' => 4],   // Turquia
        'UA' => ['zone_export' => 5, 'zone_import' => 5],   // Ucrânia
        'VA' => ['zone_export' => 4, 'zone_import' => 4],   // Vaticano

        // ── América do Norte ───────────────────────────────────────
        'US' => ['zone_export' => 6, 'zone_import' => 6],   // EUA
        'CA' => ['zone_export' => 6, 'zone_import' => 6],   // Canadá
        'MX' => ['zone_export' => 8, 'zone_import' => 8],   // México
        'PR' => ['zone_export' => 9, 'zone_import' => 9],   // Porto Rico

        // ── América Central e Caribe ───────────────────────────────
        'BS' => ['zone_export' => 9, 'zone_import' => 9],
        'BB' => ['zone_export' => 9, 'zone_import' => 9],
        'BZ' => ['zone_export' => 9, 'zone_import' => 9],
        'BM' => ['zone_export' => 9, 'zone_import' => 9],
        'CR' => ['zone_export' => 9, 'zone_import' => 9],
        'CU' => ['zone_export' => 9, 'zone_import' => 9],
        'DM' => ['zone_export' => 9, 'zone_import' => 9],
        'DO' => ['zone_export' => 9, 'zone_import' => 9],
        'SV' => ['zone_export' => 9, 'zone_import' => 9],
        'GT' => ['zone_export' => 9, 'zone_import' => 9],
        'HT' => ['zone_export' => 9, 'zone_import' => 9],
        'HN' => ['zone_export' => 9, 'zone_import' => 9],
        'JM' => ['zone_export' => 9, 'zone_import' => 9],
        'KY' => ['zone_export' => 9, 'zone_import' => 9],
        'NI' => ['zone_export' => 9, 'zone_import' => 9],
        'PA' => ['zone_export' => 9, 'zone_import' => 9],
        'TT' => ['zone_export' => 9, 'zone_import' => 9],
        'VG' => ['zone_export' => 9, 'zone_import' => 9],   // Ilhas Virgens Brit.
        'VI' => ['zone_export' => 9, 'zone_import' => 9],   // Ilhas Virgens US

        // ── América do Sul ─────────────────────────────────────────
        'AR' => ['zone_export' => 9, 'zone_import' => 9],   // Argentina
        'BR' => ['zone_export' => 6, 'zone_import' => 6],   // Brasil
        'CL' => ['zone_export' => 9, 'zone_import' => 9],   // Chile
        'CO' => ['zone_export' => 9, 'zone_import' => 9],   // Colômbia
        'EC' => ['zone_export' => 9, 'zone_import' => 9],   // Equador
        'GY' => ['zone_export' => 9, 'zone_import' => 9],   // Guiana
        'PY' => ['zone_export' => 9, 'zone_import' => 9],   // Paraguai
        'PE' => ['zone_export' => 9, 'zone_import' => 9],   // Peru
        'SR' => ['zone_export' => 9, 'zone_import' => 9],   // Suriname
        'UY' => ['zone_export' => 9, 'zone_import' => 9],   // Uruguai
        'VE' => ['zone_export' => 9, 'zone_import' => 9],   // Venezuela
        'BO' => ['zone_export' => 9, 'zone_import' => 9],   // Bolívia

        // ── Ásia (export/import zones often differ) ────────────────
        'AF' => ['zone_export' => 9, 'zone_import' => 9],
        'AM' => ['zone_export' => 5, 'zone_import' => 5],   // Arménia
        'AZ' => ['zone_export' => 5, 'zone_import' => 5],
        'BH' => ['zone_export' => 9, 'zone_import' => 9],
        'BD' => ['zone_export' => 9, 'zone_import' => 9],
        'CN' => ['zone_export' => 7, 'zone_import' => 7],   // China (export + import)
        'HK' => ['zone_export' => 7, 'zone_import' => 7],
        'IN' => ['zone_export' => 7, 'zone_import' => 7],
        'ID' => ['zone_export' => 7, 'zone_import' => 7],   // Indonésia
        'IL' => ['zone_export' => 8, 'zone_import' => 8],
        'JP' => ['zone_export' => 7, 'zone_import' => 7],
        'JO' => ['zone_export' => 9, 'zone_import' => 9],
        'KW' => ['zone_export' => 9, 'zone_import' => 9],
        'LB' => ['zone_export' => 9, 'zone_import' => 9],
        'MO' => ['zone_export' => 9, 'zone_import' => 9],   // Macau
        'MY' => ['zone_export' => 7, 'zone_import' => 7],
        'OM' => ['zone_export' => 9, 'zone_import' => 9],
        'PK' => ['zone_export' => 7, 'zone_import' => 7],
        'PH' => ['zone_export' => 7, 'zone_import' => 7],
        'QA' => ['zone_export' => 9, 'zone_import' => 9],
        'SA' => ['zone_export' => 9, 'zone_import' => 9],   // Arábia Saudita
        'SG' => ['zone_export' => 7, 'zone_import' => 7],
        'KR' => ['zone_export' => 7, 'zone_import' => 7],
        'TW' => ['zone_export' => 7, 'zone_import' => 7],
        'TH' => ['zone_export' => 7, 'zone_import' => 7],
        'AE' => ['zone_export' => 7, 'zone_import' => 7],
        'VN' => ['zone_export' => 7, 'zone_import' => 7],
        'KZ' => ['zone_export' => 5, 'zone_import' => 5],
        'KG' => ['zone_export' => 5, 'zone_import' => 5],
        'UZ' => ['zone_export' => 5, 'zone_import' => 5],
        'GE' => ['zone_export' => 5, 'zone_import' => 5],

        // ── África ─────────────────────────────────────────────────
        'DZ' => ['zone_export' => 9, 'zone_import' => 9],   // Argélia
        'AO' => ['zone_export' => 9, 'zone_import' => 9],
        'CV' => ['zone_export' => 9, 'zone_import' => 9],   // Cabo Verde
        'EG' => ['zone_export' => 8, 'zone_import' => 8],
        'GA' => ['zone_export' => 9, 'zone_import' => 9],
        'GH' => ['zone_export' => 9, 'zone_import' => 9],
        'GW' => ['zone_export' => 9, 'zone_import' => 9],   // Guiné-Bissau
        'KE' => ['zone_export' => 9, 'zone_import' => 9],
        'LY' => ['zone_export' => 9, 'zone_import' => 9],
        'MA' => ['zone_export' => 8, 'zone_import' => 8],   // Marrocos
        'MZ' => ['zone_export' => 9, 'zone_import' => 9],   // Moçambique
        'NA' => ['zone_export' => 9, 'zone_import' => 9],
        'NG' => ['zone_export' => 9, 'zone_import' => 9],
        'SN' => ['zone_export' => 9, 'zone_import' => 9],
        'ZA' => ['zone_export' => 8, 'zone_import' => 8],   // África do Sul
        'TN' => ['zone_export' => 9, 'zone_import' => 9],
        'TZ' => ['zone_export' => 9, 'zone_import' => 9],
        'UG' => ['zone_export' => 9, 'zone_import' => 9],
        'ZM' => ['zone_export' => 9, 'zone_import' => 9],
        'ZW' => ['zone_export' => 9, 'zone_import' => 9],

        // ── Oceânia ────────────────────────────────────────────────
        'AU' => ['zone_export' => 8, 'zone_import' => 8],
        'NZ' => ['zone_export' => 8, 'zone_import' => 8],
        'FJ' => ['zone_export' => 9, 'zone_import' => 9],
        'PG' => ['zone_export' => 9, 'zone_import' => 9],
    ];

    /**
     * PT-language country names for display in agent replies.
     * Subset — falls back to ISO-2 if not present.
     */
    public const PT_NAME = [
        'ES' => 'Espanha', 'FR' => 'França', 'DE' => 'Alemanha', 'IT' => 'Itália',
        'GB' => 'Reino Unido', 'NL' => 'Países Baixos', 'BE' => 'Bélgica',
        'LU' => 'Luxemburgo', 'IE' => 'Irlanda', 'AT' => 'Áustria',
        'CH' => 'Suíça', 'NO' => 'Noruega', 'SE' => 'Suécia', 'DK' => 'Dinamarca',
        'FI' => 'Finlândia', 'PL' => 'Polónia', 'CZ' => 'Rep. Checa',
        'HU' => 'Hungria', 'GR' => 'Grécia', 'RO' => 'Roménia', 'BG' => 'Bulgária',
        'US' => 'Estados Unidos', 'CA' => 'Canadá', 'BR' => 'Brasil',
        'MX' => 'México', 'AR' => 'Argentina',
        'CN' => 'China', 'JP' => 'Japão', 'KR' => 'Coreia do Sul', 'IN' => 'Índia',
        'HK' => 'Hong Kong', 'SG' => 'Singapura', 'TW' => 'Taiwan',
        'AU' => 'Austrália', 'NZ' => 'Nova Zelândia', 'ZA' => 'África do Sul',
        'AE' => 'Emirados Árabes Unidos', 'SA' => 'Arábia Saudita',
        'AO' => 'Angola', 'CV' => 'Cabo Verde', 'MZ' => 'Moçambique',
        'MA' => 'Marrocos', 'PT' => 'Portugal',
    ];

    /**
     * Free-text alias → ISO-2 for resolving user input.
     * Lowercase, no accents (see ShippingRateService::normalise).
     */
    public const ALIASES = [
        'portugal' => 'PT', 'pt' => 'PT',
        'espanha' => 'ES', 'spain' => 'ES', 'es' => 'ES',
        'franca' => 'FR', 'france' => 'FR',
        'alemanha' => 'DE', 'germany' => 'DE',
        'italia' => 'IT', 'italy' => 'IT',
        'reino unido' => 'GB', 'inglaterra' => 'GB', 'uk' => 'GB',
        'paises baixos' => 'NL', 'holanda' => 'NL', 'netherlands' => 'NL',
        'belgica' => 'BE', 'belgium' => 'BE',
        'luxemburgo' => 'LU',
        'irlanda' => 'IE', 'ireland' => 'IE',
        'austria' => 'AT',
        'suica' => 'CH', 'switzerland' => 'CH',
        'noruega' => 'NO', 'norway' => 'NO',
        'suecia' => 'SE', 'sweden' => 'SE',
        'dinamarca' => 'DK', 'denmark' => 'DK',
        'finlandia' => 'FI', 'finland' => 'FI',
        'polonia' => 'PL', 'poland' => 'PL',
        'grecia' => 'GR', 'greece' => 'GR',
        'romenia' => 'RO',
        'estados unidos' => 'US', 'eua' => 'US', 'usa' => 'US',
        'canada' => 'CA',
        'brasil' => 'BR', 'brazil' => 'BR',
        'mexico' => 'MX',
        'argentina' => 'AR',
        'china' => 'CN',
        'japao' => 'JP', 'japan' => 'JP',
        'india' => 'IN',
        'hong kong' => 'HK', 'hongkong' => 'HK',
        'singapura' => 'SG', 'singapore' => 'SG',
        'taiwan' => 'TW',
        'coreia do sul' => 'KR', 'south korea' => 'KR',
        'australia' => 'AU',
        'nova zelandia' => 'NZ', 'new zealand' => 'NZ',
        'africa do sul' => 'ZA', 'south africa' => 'ZA',
        'emirados' => 'AE', 'eau' => 'AE',
        'arabia saudita' => 'SA',
        'angola' => 'AO', 'cabo verde' => 'CV', 'cv' => 'CV',
        'mocambique' => 'MZ', 'moz' => 'MZ',
        'marrocos' => 'MA', 'morocco' => 'MA',
    ];
}
