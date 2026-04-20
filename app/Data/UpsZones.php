<?php

namespace App\Data;

/**
 * UPS country → zone mapping for the four services PartYard has under
 * contract Q9717213PT. Derived from the UPS 2026 Tabela de Zonas
 * ("ZONAS 2026.pdf") + the PartYard tariff spreadsheet.
 *
 * Each entry maps the destination/origin country (ISO-2 code) to the
 * zone number used in UpsRates::TARIFFS.
 *
 * Keys inside each country entry:
 *   - send_express_saver:    zone when we ship OUT of Portugal
 *   - receive_express:       zone when a partner sends to us
 *   - receive_express_saver: idem, Express Saver tier
 *   - receive_expedited:     idem, Expedited tier (not available to every country)
 *
 * Null = the service is not available for that route under this contract.
 *
 * Coverage: the most relevant 90+ countries for PartYard's client base
 * (EU-27, US, UK, Brazil, Canada, China, Japan, India, GCC, key NATO
 * markets, major shipping hubs). For obscure destinations, the service
 * returns a "zone unknown" response and the agent should direct the
 * user to ups.com/calculator.
 */
class UpsZones
{
    public const PT_NAME = [
        'AT' => 'Áustria', 'BE' => 'Bélgica', 'BG' => 'Bulgária', 'HR' => 'Croácia',
        'CY' => 'Chipre',  'CZ' => 'Rep. Checa', 'DK' => 'Dinamarca', 'EE' => 'Estónia',
        'FI' => 'Finlândia', 'FR' => 'França', 'DE' => 'Alemanha', 'GR' => 'Grécia',
        'HU' => 'Hungria', 'IE' => 'Irlanda',  'IT' => 'Itália', 'LV' => 'Letónia',
        'LT' => 'Lituânia','LU' => 'Luxemburgo','MT' => 'Malta', 'NL' => 'Holanda',
        'PL' => 'Polónia', 'PT' => 'Portugal', 'RO' => 'Roménia', 'SK' => 'Eslováquia',
        'SI' => 'Eslovénia','ES' => 'Espanha', 'SE' => 'Suécia',
        'GB' => 'Reino Unido', 'CH' => 'Suíça', 'NO' => 'Noruega', 'IS' => 'Islândia',
        'US' => 'EUA', 'CA' => 'Canadá', 'MX' => 'México',
        'BR' => 'Brasil', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colômbia',
        'PE' => 'Peru', 'UY' => 'Uruguai', 'VE' => 'Venezuela',
        'CN' => 'China', 'HK' => 'Hong Kong', 'JP' => 'Japão', 'KR' => 'Coreia do Sul',
        'TW' => 'Taiwan', 'SG' => 'Singapura', 'MY' => 'Malásia', 'TH' => 'Tailândia',
        'VN' => 'Vietname', 'ID' => 'Indonésia', 'PH' => 'Filipinas', 'IN' => 'Índia',
        'AE' => 'Emirados Árabes', 'SA' => 'Arábia Saudita', 'IL' => 'Israel',
        'TR' => 'Turquia', 'EG' => 'Egipto', 'MA' => 'Marrocos', 'ZA' => 'África do Sul',
        'AU' => 'Austrália', 'NZ' => 'Nova Zelândia',
    ];

    /**
     * country_iso => [service_key => zone_number|null]
     *
     * Zones for send_express_saver come from the "Zonas para Enviar · Express Saver"
     * column. Zones for receive_* come from the corresponding "Zonas para Receber"
     * columns. Values that appear in the rate table take precedence.
     */
    public const ZONE_MAP = [
        // ── EU core ─────────────────────────────────────────────────────────
        'PT' => ['send_express_saver' => 1,  'receive_express' => 1,  'receive_express_saver' => 1,  'receive_expedited' => 1],
        'ES' => ['send_express_saver' => 2,  'receive_express' => 2,  'receive_express_saver' => 2,  'receive_expedited' => 2],
        'FR' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'BE' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'NL' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'DE' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'IT' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'LU' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 3],
        'AT' => ['send_express_saver' => 3,  'receive_express' => 3,  'receive_express_saver' => 3,  'receive_expedited' => 4],
        'DK' => ['send_express_saver' => 4,  'receive_express' => 4,  'receive_express_saver' => 4,  'receive_expedited' => 4],
        'IE' => ['send_express_saver' => 4,  'receive_express' => 4,  'receive_express_saver' => 4,  'receive_expedited' => 4],
        'SE' => ['send_express_saver' => 4,  'receive_express' => 4,  'receive_express_saver' => 4,  'receive_expedited' => 4],
        'FI' => ['send_express_saver' => 4,  'receive_express' => 4,  'receive_express_saver' => 4,  'receive_expedited' => 4],
        'PL' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => 41],
        'CZ' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => 41],
        'SK' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => 41],
        'HU' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => 41],
        'RO' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => 42],
        'BG' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => 42],
        'LT' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => 42],
        'LV' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => 42],
        'EE' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => 42],
        'HR' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => null],
        'SI' => ['send_express_saver' => 41, 'receive_express' => 41, 'receive_express_saver' => 41, 'receive_expedited' => null],
        'CY' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => null],
        'MT' => ['send_express_saver' => 42, 'receive_express' => 42, 'receive_express_saver' => 42, 'receive_expedited' => null],
        'GR' => ['send_express_saver' => 4,  'receive_express' => 4,  'receive_express_saver' => 4,  'receive_expedited' => 4],

        // ── Europa não-UE ───────────────────────────────────────────────────
        'GB' => ['send_express_saver' => 703, 'receive_express' => 753, 'receive_express_saver' => 753, 'receive_expedited' => null],
        'CH' => ['send_express_saver' => 51,  'receive_express' => 5,   'receive_express_saver' => 5,   'receive_expedited' => null],
        'NO' => ['send_express_saver' => 51,  'receive_express' => 5,   'receive_express_saver' => 5,   'receive_expedited' => null],
        'IS' => ['send_express_saver' => 5,   'receive_express' => 6,   'receive_express_saver' => 6,   'receive_expedited' => null],

        // ── América do Norte ────────────────────────────────────────────────
        'US' => ['send_express_saver' => 5,   'receive_express' => 7,   'receive_express_saver' => 7,   'receive_expedited' => null],
        'CA' => ['send_express_saver' => 6,   'receive_express' => 7,   'receive_express_saver' => 7,   'receive_expedited' => null],
        'MX' => ['send_express_saver' => 8,   'receive_express' => 7,   'receive_express_saver' => 7,   'receive_expedited' => null],

        // ── América Latina ──────────────────────────────────────────────────
        'BR' => ['send_express_saver' => 7,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'AR' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'CL' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'CO' => ['send_express_saver' => 9,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'PE' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'UY' => ['send_express_saver' => 9,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'VE' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],

        // ── Ásia ─────────────────────────────────────────────────────────────
        'CN' => ['send_express_saver' => 8,   'receive_express' => 10,  'receive_express_saver' => 10,  'receive_expedited' => null],
        'HK' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'JP' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'KR' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'TW' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'SG' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'MY' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'TH' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'VN' => ['send_express_saver' => 9,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'ID' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'PH' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'IN' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],

        // ── Médio Oriente ───────────────────────────────────────────────────
        'AE' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'SA' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'IL' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'QA' => ['send_express_saver' => 9,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'KW' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'OM' => ['send_express_saver' => 9,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'BH' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'JO' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'LB' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'IR' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'IQ' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'TR' => ['send_express_saver' => 7,   'receive_express' => 81,  'receive_express_saver' => 81,  'receive_expedited' => null],

        // ── África ──────────────────────────────────────────────────────────
        'EG' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'MA' => ['send_express_saver' => 7,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'ZA' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
        'AO' => ['send_express_saver' => 9,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'MZ' => ['send_express_saver' => 9,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'CV' => ['send_express_saver' => 8,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'NG' => ['send_express_saver' => 8,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'KE' => ['send_express_saver' => 9,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'SN' => ['send_express_saver' => 8,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'GH' => ['send_express_saver' => 9,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'TN' => ['send_express_saver' => 8,   'receive_express' => 11,  'receive_express_saver' => 11,  'receive_expedited' => null],
        'DZ' => ['send_express_saver' => 7,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],

        // ── Oceania ─────────────────────────────────────────────────────────
        'AU' => ['send_express_saver' => 8,   'receive_express' => 8,   'receive_express_saver' => 8,   'receive_expedited' => null],
        'NZ' => ['send_express_saver' => 8,   'receive_express' => 9,   'receive_express_saver' => 9,   'receive_expedited' => null],
    ];

    /**
     * Friendly name → ISO-2 fuzzy-match index. Used by the ShippingRateService
     * so agents can say "ship to Brasil" / "United States" / "Espanha" and
     * the service resolves to ISO codes. Case-insensitive, accent-stripped.
     */
    public const ALIASES = [
        'portugal' => 'PT', 'espanha' => 'ES', 'spain' => 'ES',
        'franca' => 'FR', 'france' => 'FR',
        'alemanha' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE',
        'italia' => 'IT', 'italy' => 'IT',
        'holanda' => 'NL', 'paises baixos' => 'NL', 'netherlands' => 'NL',
        'belgica' => 'BE', 'belgium' => 'BE',
        'luxemburgo' => 'LU', 'luxembourg' => 'LU',
        'austria' => 'AT',
        'dinamarca' => 'DK', 'denmark' => 'DK',
        'irlanda' => 'IE', 'ireland' => 'IE',
        'suecia' => 'SE', 'sweden' => 'SE',
        'finlandia' => 'FI', 'finland' => 'FI',
        'grecia' => 'GR', 'greece' => 'GR',
        'polonia' => 'PL', 'poland' => 'PL',
        'republica checa' => 'CZ', 'czechia' => 'CZ', 'chequia' => 'CZ',
        'eslovaquia' => 'SK', 'slovakia' => 'SK',
        'eslovenia' => 'SI', 'slovenia' => 'SI',
        'hungria' => 'HU', 'hungary' => 'HU',
        'croacia' => 'HR', 'croatia' => 'HR',
        'romenia' => 'RO', 'romania' => 'RO',
        'bulgaria' => 'BG',
        'estonia' => 'EE',
        'letonia' => 'LV', 'latvia' => 'LV',
        'lituania' => 'LT', 'lithuania' => 'LT',
        'chipre' => 'CY', 'cyprus' => 'CY',
        'malta' => 'MT',
        'reino unido' => 'GB', 'inglaterra' => 'GB', 'uk' => 'GB', 'united kingdom' => 'GB', 'england' => 'GB',
        'suica' => 'CH', 'switzerland' => 'CH',
        'noruega' => 'NO', 'norway' => 'NO',
        'islandia' => 'IS', 'iceland' => 'IS',
        'eua' => 'US', 'estados unidos' => 'US', 'usa' => 'US', 'united states' => 'US', 'america' => 'US',
        'canada' => 'CA',
        'mexico' => 'MX',
        'brasil' => 'BR', 'brazil' => 'BR',
        'argentina' => 'AR', 'chile' => 'CL', 'colombia' => 'CO', 'peru' => 'PE',
        'uruguai' => 'UY', 'uruguay' => 'UY', 'venezuela' => 'VE',
        'china' => 'CN',
        'hong kong' => 'HK',
        'japao' => 'JP', 'japan' => 'JP',
        'coreia do sul' => 'KR', 'coreia' => 'KR', 'south korea' => 'KR', 'korea' => 'KR',
        'taiwan' => 'TW',
        'singapura' => 'SG', 'singapore' => 'SG',
        'malasia' => 'MY', 'malaysia' => 'MY',
        'tailandia' => 'TH', 'thailand' => 'TH',
        'vietname' => 'VN', 'vietnam' => 'VN',
        'indonesia' => 'ID',
        'filipinas' => 'PH', 'philippines' => 'PH',
        'india' => 'IN',
        'emirados' => 'AE', 'emirados arabes unidos' => 'AE', 'eau' => 'AE', 'uae' => 'AE',
        'arabia saudita' => 'SA', 'saudi arabia' => 'SA',
        'israel' => 'IL',
        'qatar' => 'QA',
        'kuwait' => 'KW',
        'oma' => 'OM', 'oman' => 'OM',
        'bahrein' => 'BH', 'bahrain' => 'BH',
        'jordania' => 'JO', 'jordan' => 'JO',
        'libano' => 'LB', 'lebanon' => 'LB',
        'ira' => 'IR', 'iran' => 'IR',
        'iraque' => 'IQ', 'iraq' => 'IQ',
        'turquia' => 'TR', 'turkey' => 'TR',
        'egipto' => 'EG', 'egito' => 'EG', 'egypt' => 'EG',
        'marrocos' => 'MA', 'morocco' => 'MA',
        'africa do sul' => 'ZA', 'south africa' => 'ZA', 'rsa' => 'ZA',
        'angola' => 'AO', 'mocambique' => 'MZ', 'mozambique' => 'MZ',
        'cabo verde' => 'CV', 'nigeria' => 'NG', 'quenia' => 'KE', 'kenya' => 'KE',
        'senegal' => 'SN', 'gana' => 'GH', 'ghana' => 'GH',
        'tunisia' => 'TN', 'argelia' => 'DZ', 'algeria' => 'DZ',
        'australia' => 'AU', 'nova zelandia' => 'NZ', 'new zealand' => 'NZ',
    ];
}
