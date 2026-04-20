<?php

namespace App\Services;

use App\Data\UpsRates;
use App\Data\UpsZones;

/**
 * ShippingRateService — compute UPS shipping quotes for PartYard.
 *
 * Consumes the static tariff table (UpsRates) and zone map (UpsZones)
 * to produce a price estimate for any (origin, destination, weight,
 * dimensions, service) combination under the PartYard contract.
 *
 * NOTE: prices exclude VAT and UPS surcharges (fuel, remote area,
 * oversize, additional handling) — agents must tell the user the
 * quote is indicative.
 */
class ShippingRateService
{
    /** Divisor used to derive volumetric weight from cm³ (UPS standard). */
    public const VOLUMETRIC_DIVISOR = 5000;

    /** Max per-package real weight in kg (UPS 2026 limit). */
    public const MAX_PACKAGE_KG = 70;

    /**
     * Resolve a free-text country reference (name, ISO-2, alias) to ISO-2.
     * Returns null if the reference can't be matched.
     */
    public function resolveCountry(?string $ref): ?string
    {
        if (!$ref) return null;
        $raw = trim($ref);
        // Already an ISO-2?
        if (preg_match('/^[A-Z]{2}$/', strtoupper($raw))) {
            $iso = strtoupper($raw);
            if (isset(UpsZones::ZONE_MAP[$iso])) return $iso;
        }
        $norm = $this->normalise($raw);
        if ($norm === '') return null;
        return UpsZones::ALIASES[$norm] ?? null;
    }

    /**
     * Look up the UPS zone for a given ISO-2 country and service.
     */
    public function zoneFor(string $iso, string $service): ?int
    {
        $entry = UpsZones::ZONE_MAP[$iso] ?? null;
        if (!$entry) return null;
        $zone = $entry[$service] ?? null;
        return is_int($zone) ? $zone : null;
    }

    /**
     * Calculate volumetric (dimensional) weight in kg from cm dimensions.
     * UPS charges on max(real, volumetric).
     */
    public function volumetricWeight(float $length, float $width, float $height): float
    {
        return round(($length * $width * $height) / self::VOLUMETRIC_DIVISOR, 2);
    }

    /**
     * Primary quote entry point.
     *
     * $opts keys:
     *   - origin        string   ISO-2 or alias (default PT)
     *   - destination   string   ISO-2 or alias (required)
     *   - weight_kg     float    real weight
     *   - length_cm     float|null
     *   - width_cm      float|null
     *   - height_cm     float|null
     *   - service       string|null  one of UpsRates::TARIFFS keys; auto if null
     *
     * Returns an array suitable for agent output — see keys in the code
     * below.
     */
    public function quote(array $opts): array
    {
        $origin   = $this->resolveCountry($opts['origin'] ?? 'PT') ?? 'PT';
        $destRaw  = $opts['destination'] ?? null;
        $dest     = $this->resolveCountry($destRaw);
        $weight   = max(0.0, (float) ($opts['weight_kg'] ?? 0));
        $length   = (float) ($opts['length_cm'] ?? 0);
        $width    = (float) ($opts['width_cm']  ?? 0);
        $height   = (float) ($opts['height_cm'] ?? 0);
        $service  = $opts['service'] ?? null;

        if (!$dest) {
            return [
                'ok'      => false,
                'error'   => 'Destino não reconhecido.',
                'hint'    => 'Usa o nome do país (ex: "Brasil", "Reino Unido") ou o código ISO-2 ("BR", "GB").',
                'given'   => $destRaw,
            ];
        }

        // Auto-pick service: Express Saver for send (PT→X) or receive (X→PT).
        if (!$service) {
            $service = $origin === 'PT'
                ? 'send_express_saver'
                : 'receive_express_saver';
        }
        if (!isset(UpsRates::TARIFFS[$service])) {
            return ['ok' => false, 'error' => "Serviço desconhecido: {$service}"];
        }

        // For `send_*` services the zone is looked up on the destination;
        // for `receive_*` services the zone is looked up on the ORIGIN
        // (partner that is shipping to us). If the caller mixes them up
        // we use the non-PT side as the zone reference.
        $isReceive = str_starts_with($service, 'receive_');
        $zoneCountry = $isReceive ? $origin : $dest;
        if ($zoneCountry === 'PT') {
            // Fallback: if both sides are PT or caller passed only one side,
            // use whichever non-PT country is present.
            $zoneCountry = $dest !== 'PT' ? $dest : $origin;
        }

        $zone = $this->zoneFor($zoneCountry, $service);
        if (!$zone) {
            return [
                'ok'        => false,
                'error'     => "Zona UPS indisponível para {$zoneCountry} ({$service}).",
                'hint'      => 'Este destino não está coberto pelo contrato PartYard para este serviço, ou não tem mapeamento configurado. Consulta ups.com/calculate.',
            ];
        }

        // Volumetric weight check
        $volumetric = 0.0;
        if ($length > 0 && $width > 0 && $height > 0) {
            $volumetric = $this->volumetricWeight($length, $width, $height);
        }
        $billingWeight = max($weight, $volumetric);
        if ($billingWeight <= 0) {
            return [
                'ok'   => false,
                'error' => 'Indica o peso (kg) ou as dimensões (LxWxH em cm).',
            ];
        }

        if ($weight > self::MAX_PACKAGE_KG) {
            return [
                'ok'    => false,
                'error' => 'Peso por pacote acima do limite UPS (70 kg). Considera palete / WW Express Freight.',
            ];
        }

        // Find the right rate row
        $rows = UpsRates::TARIFFS[$service];
        $priced = $this->priceForWeight($rows, (string) $zone, $billingWeight);
        if (!$priced['ok']) {
            return [
                'ok'    => false,
                'error' => "Sem tarifa para zona {$zone} nesta gama de peso.",
            ];
        }

        return [
            'ok'             => true,
            'origin'         => $origin,
            'destination'    => $dest,
            'destination_pt' => UpsZones::PT_NAME[$dest] ?? $dest,
            'zone'           => $zone,
            'service'        => $service,
            'service_label'  => UpsRates::SERVICE_LABELS[$service] ?? $service,
            'real_weight'    => round($weight, 2),
            'volumetric_kg'  => $volumetric,
            'billing_weight' => round($billingWeight, 2),
            'currency'       => UpsRates::CURRENCY,
            'price_excl_vat' => round($priced['price'], 2),
            'base_rate'      => round($priced['base_rate'], 2),
            'breakpoint_kg'  => $priced['breakpoint_kg'],
            'overflow_kg'    => $priced['overflow_kg'],
            'overflow_rate'  => $priced['overflow_rate'],
            'contract'       => UpsRates::CONTRACT,
            'effective_to'   => UpsRates::EFFECTIVE_TO,
            'disclaimer'     => 'Valor indicativo — exclui IVA, taxa de combustível, sobretaxas de área remota e manuseamento adicional.',
        ];
    }

    /**
     * Given ordered tariff rows + zone + weight, return the final EUR price.
     *
     * Rate rows carry one of three "type" markers:
     *   Por Env        — minimum shipment charge (kg = 0)
     *   Por envio      — flat rate up to that kg breakpoint
     *   Por kg (9999999)— additional EUR/kg for weight above the last breakpoint
     *
     * We:
     *   1. Extract the minimum charge (Por Env).
     *   2. Find the smallest kg breakpoint >= billing_weight → that's the base rate.
     *   3. If weight exceeds the highest "Por envio" breakpoint, use it plus
     *      (weight - max_kg) * per_kg_rate from the Por kg row.
     *   4. Return max(minimum, computed).
     */
    private function priceForWeight(array $rows, string $zone, float $weight): array
    {
        $minimum  = null;
        $perKg    = null;
        $maxFixed = null;    // highest kg with a "Por envio" price
        $maxFixedPrice = null;
        $selected = null;    // row that matches the weight

        foreach ($rows as $r) {
            $type  = $r['type'] ?? '';
            $kg    = (float) ($r['kg'] ?? 0);
            $price = $r['p'][$zone] ?? null;

            if ($price === null) continue;

            if ($type === 'Por Env') {
                $minimum = (float) $price;
            } elseif (stripos($type, 'Por kg') !== false || $kg >= 9_000_000) {
                $perKg = (float) $price;
            } else {
                // "Por envio" / "Por Envio"
                if ($maxFixed === null || $kg > $maxFixed) {
                    $maxFixed      = $kg;
                    $maxFixedPrice = (float) $price;
                }
                if ($selected === null && $kg >= $weight) {
                    $selected = ['kg' => $kg, 'price' => (float) $price];
                }
            }
        }

        if ($selected) {
            $price = $selected['price'];
            if ($minimum !== null) $price = max($price, $minimum);
            return [
                'ok'            => true,
                'price'         => $price,
                'base_rate'     => $selected['price'],
                'breakpoint_kg' => $selected['kg'],
                'overflow_kg'   => 0.0,
                'overflow_rate' => 0.0,
            ];
        }

        // Weight exceeds biggest fixed breakpoint — apply per-kg surcharge.
        if ($maxFixed !== null && $perKg !== null) {
            $overflow = $weight - $maxFixed;
            $price    = $maxFixedPrice + ($overflow * $perKg);
            if ($minimum !== null) $price = max($price, $minimum);
            return [
                'ok'            => true,
                'price'         => $price,
                'base_rate'     => $maxFixedPrice,
                'breakpoint_kg' => $maxFixed,
                'overflow_kg'   => round($overflow, 2),
                'overflow_rate' => $perKg,
            ];
        }

        return ['ok' => false];
    }

    /**
     * Human-readable summary of a quote — format the agents paste into replies.
     */
    public function formatQuote(array $q): string
    {
        if (!($q['ok'] ?? false)) {
            $msg = '❌ ' . ($q['error'] ?? 'Não consegui calcular.');
            if (!empty($q['hint'])) $msg .= "\n" . $q['hint'];
            return $msg;
        }

        $lines = [
            '📦 **Estimativa UPS (contrato PartYard)**',
            '',
            '- **Rota:** ' . $q['origin'] . ' → ' . $q['destination']
                . ' (' . $q['destination_pt'] . ', zona ' . $q['zone'] . ')',
            '- **Serviço:** ' . $q['service_label'],
            '- **Peso faturável:** ' . $q['billing_weight'] . ' kg'
                . ($q['volumetric_kg'] > $q['real_weight']
                    ? ' *(volumétrico ' . $q['volumetric_kg'] . ' kg > real ' . $q['real_weight'] . ' kg)*'
                    : ''),
            '- **Preço estimado:** **' . number_format($q['price_excl_vat'], 2, ',', ' ') . ' €** (excl. IVA)',
        ];

        if ($q['overflow_kg'] > 0) {
            $lines[] = '   ↳ base ' . number_format($q['base_rate'], 2, ',', ' ') . ' € até '
                     . $q['breakpoint_kg'] . ' kg + '
                     . $q['overflow_kg'] . ' kg × '
                     . number_format($q['overflow_rate'], 2, ',', ' ') . ' €/kg';
        }

        $lines[] = '';
        $lines[] = '_' . $q['disclaimer'] . '_';
        $lines[] = '_Contrato ' . $q['contract'] . ' válido até ' . $q['effective_to'] . '._';

        return implode("\n", $lines);
    }

    /**
     * Strip accents + lowercase + collapse spaces for alias matching.
     */
    private function normalise(string $s): string
    {
        $s = strtolower(trim($s));
        // Remove common accents
        $from = ['á','à','â','ã','ä','é','ê','ë','í','î','ï','ó','ô','õ','ö','ú','û','ü','ç','ñ'];
        $to   = ['a','a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','c','n'];
        $s = str_replace($from, $to, $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace('/[^a-z\s]/', '', $s);
        return trim($s);
    }

    /**
     * Returns a compact routing/capability description suitable for
     * embedding in agent system prompts. Used by ShippingSkillTrait.
     */
    public static function skillPromptBlock(): string
    {
        $contract = UpsRates::CONTRACT;
        $effective = UpsRates::EFFECTIVE_TO;
        return <<<SKILL

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🚚 SKILL: ESTIMATIVAS DE TRANSPORTE UPS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Tens acesso às tarifas PartYard (contrato {$contract}, válido até {$effective})
para estimativas de transporte UPS. Quando o utilizador pedir um custo
aproximado de envio, usa os dados disponíveis:

 · Serviços cobertos: UPS Express Saver (envio PT→destino e receção
   origem→PT), UPS Express (receção), UPS Expedited (receção).
 · Zonas principais: PT=1, ES=2, FR/DE/IT/BE/NL/LU=3, DK/IE/SE/FI/GR=4,
   PL/CZ/SK/HU=41, RO/BG/Balticos=42, GB=703/753, CH/NO=51/5,
   US=5/7, CA=6/7, BR=7/9, CN=8/10, AU=8, EAU/Israel=8/9.
 · Cada pacote pesa no máximo 70 kg reais; UPS cobra o maior entre
   peso real e volumétrico (L×W×H / 5000).

Se o utilizador fornecer origem, destino, peso e (opcional) dimensões,
indica o valor estimado em EUR (excl. IVA) e avisa que é indicativo —
exclui IVA, taxa de combustível e sobretaxas de área remota.

Para cálculos precisos, existe o endpoint interno ShippingRateService::quote()
usado pelo agente RoboTransportes (key "shipping").

SKILL;
    }
}
