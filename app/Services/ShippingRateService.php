<?php

namespace App\Services;

use App\Data\FedExRates;
use App\Data\FedExZones;
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

    // ═══════════════════════════════════════════════════════════════
    //  FedEx / TNT quotes — same data shape as UPS so we can reuse
    //  priceForWeight() under the hood, but with different zone map,
    //  volumetric divisor (150 vs 5000) and rate table.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve a country (ISO-2 or alias) using FedEx alias table.
     * Falls back to UPS alias table for shared names.
     */
    public function resolveCountryFedEx(?string $ref): ?string
    {
        if (!$ref) return null;
        $raw = trim($ref);
        if (preg_match('/^[A-Z]{2}$/', strtoupper($raw))) {
            $iso = strtoupper($raw);
            if (isset(FedExZones::ZONE_MAP[$iso]) || $iso === 'PT') return $iso;
        }
        $norm = $this->normalise($raw);
        return FedExZones::ALIASES[$norm] ?? UpsZones::ALIASES[$norm] ?? null;
    }

    /**
     * Look up FedEx zone for country given direction (export vs import).
     */
    public function fedExZoneFor(string $iso, bool $isReceive): ?int
    {
        $entry = FedExZones::ZONE_MAP[$iso] ?? null;
        if (!$entry) return null;
        $zone = $isReceive ? ($entry['zone_import'] ?? null) : ($entry['zone_export'] ?? null);
        return is_int($zone) ? $zone : null;
    }

    /**
     * Detect PT domestic zone from a postal code (Zona 2 == 5000-5999,
     * 6050, 7000-8999; Madeira == 9000-9499; Açores == 9500-9999;
     * everything else continental == Zona 1).
     */
    public function ptDomesticZone(?string $postalCode): string
    {
        if (!$postalCode) return 'pt1';
        $pc = (int) preg_replace('/[^0-9]/', '', substr($postalCode, 0, 4));
        if ($pc >= 9500 && $pc <= 9999) return 'pt_a';   // Açores
        if ($pc >= 9000 && $pc <= 9499) return 'pt_m';   // Madeira
        if (($pc >= 5000 && $pc <= 5999)
            || $pc === 6050
            || ($pc >= 7000 && $pc <= 8999)) {
            return 'pt2';
        }
        return 'pt1';
    }

    /**
     * Volumetric weight for FedEx — divisor depends on continent (150)
     * vs ilhas (250) for PT domestic; international uses 5000 like UPS.
     */
    public function fedExVolumetric(float $l, float $w, float $h, string $zone): float
    {
        // For PT domestic m³ values are expected (1.2m × 1.2m × 1.8m max)
        // For international we assume cm dimensions like UPS
        if (str_starts_with($zone, 'pt_')) {
            $divisor = ($zone === 'pt_m' || $zone === 'pt_a')
                ? FedExRates::VOLUMETRIC_DIVISOR_ISLANDS
                : FedExRates::VOLUMETRIC_DIVISOR_CONTINENT;
            // PT divisor uses metres × kg/m³, so dimensions are already in metres
            return round(($l * $w * $h) * $divisor, 2);
        }
        return round(($l * $w * $h) / FedExRates::VOLUMETRIC_DIVISOR_DEFAULT, 2);
    }

    /**
     * Primary FedEx quote entry point. Same input shape as quote() with
     * extra optional `postal_code` for PT domestic zone routing.
     *
     * Returns the same structured array — caller can use formatFedExQuote().
     */
    public function quoteFedEx(array $opts): array
    {
        $origin   = $this->resolveCountryFedEx($opts['origin'] ?? 'PT') ?? 'PT';
        $destRaw  = $opts['destination'] ?? null;
        $dest     = $this->resolveCountryFedEx($destRaw);
        $weight   = max(0.0, (float) ($opts['weight_kg'] ?? 0));
        $length   = (float) ($opts['length_cm'] ?? 0);
        $width    = (float) ($opts['width_cm']  ?? 0);
        $height   = (float) ($opts['height_cm'] ?? 0);
        $service  = $opts['service'] ?? null;
        $postal   = $opts['postal_code'] ?? null;

        if (!$dest) {
            return [
                'ok'    => false,
                'error' => 'Destino não reconhecido para FedEx.',
                'hint'  => 'Usa nome do país (ex: "Brasil") ou ISO-2 ("BR"). FedEx cobre 88 países nesta tabela; outros = sem cotação automática.',
                'given' => $destRaw,
            ];
        }

        // ── Domestic PT (PT → PT) ──
        if ($origin === 'PT' && $dest === 'PT') {
            $zone = $this->ptDomesticZone($postal);
            $service = 'pt_domestic';
            $rows    = FedExRates::TARIFFS[$service];

            $volumetric = $length > 0 && $width > 0 && $height > 0
                ? $this->fedExVolumetric($length, $width, $height, $zone)
                : 0.0;
            $billing = max($weight, $volumetric);
            if ($billing <= 0) {
                return ['ok' => false, 'error' => 'Indica peso (kg) ou dimensões (LxWxH em m).'];
            }

            $priced = $this->priceForWeight($rows, $zone, $billing);
            if (!$priced['ok']) {
                return ['ok' => false, 'error' => "Sem tarifa FedEx PT para esta gama de peso."];
            }
            $price = $priced['price'] * FedExRates::CONTRACT_DISCOUNT;

            $zoneLabel = match ($zone) {
                'pt1'  => 'PT Continental — Zona 1',
                'pt2'  => 'PT Continental — Zona 2 (5000-5999, 6050, 7000-8999)',
                'pt_m' => 'Madeira',
                'pt_a' => 'Açores',
            };

            return $this->buildFedExResult(
                origin: $origin, dest: $dest, zoneLabel: $zoneLabel, zoneNum: $zone,
                service: $service, weight: $weight, volumetric: $volumetric, billing: $billing,
                price: $price, priced: $priced,
            );
        }

        // ── International ──
        if (!$service) $service = 'int_express';
        if (!isset(FedExRates::TARIFFS[$service])) {
            return ['ok' => false, 'error' => "Serviço FedEx desconhecido: {$service}. Disponível: int_express, int_economy, pt_domestic."];
        }

        $isReceive   = str_starts_with($service, 'receive_') || ($opts['direction'] ?? '') === 'receive';
        $zoneCountry = $isReceive ? $origin : $dest;
        if ($zoneCountry === 'PT') $zoneCountry = $dest !== 'PT' ? $dest : $origin;

        $zone = $this->fedExZoneFor($zoneCountry, $isReceive);
        if (!$zone) {
            return [
                'ok'    => false,
                'error' => "Zona FedEx indisponível para {$zoneCountry}.",
                'hint'  => 'Destino não coberto pela tabela TNT 2026, ou export/import zone não mapeada. Consulta tnt.com ou pede cotação manual.',
            ];
        }

        $volumetric = $length > 0 && $width > 0 && $height > 0
            ? round(($length * $width * $height) / FedExRates::VOLUMETRIC_DIVISOR_DEFAULT, 2)
            : 0.0;
        $billing = max($weight, $volumetric);
        if ($billing <= 0) {
            return ['ok' => false, 'error' => 'Indica o peso (kg) ou as dimensões (LxWxH em cm).'];
        }

        $rows   = FedExRates::TARIFFS[$service];
        $priced = $this->priceForWeight($rows, (string) $zone, $billing);
        if (!$priced['ok']) {
            return ['ok' => false, 'error' => "Sem tarifa FedEx para zona {$zone} nesta gama de peso."];
        }
        $price = $priced['price'] * FedExRates::CONTRACT_DISCOUNT;

        return $this->buildFedExResult(
            origin: $origin, dest: $dest, zoneLabel: "Internacional Zona {$zone}", zoneNum: (string) $zone,
            service: $service, weight: $weight, volumetric: $volumetric, billing: $billing,
            price: $price, priced: $priced,
        );
    }

    private function buildFedExResult(
        string $origin, string $dest, string $zoneLabel, string $zoneNum,
        string $service, float $weight, float $volumetric, float $billing,
        float $price, array $priced,
    ): array {
        $hasDiscount = FedExRates::HAS_CONTRACT_DISCOUNT;
        $multiplier  = FedExRates::CONTRACT_DISCOUNT;
        // Discount % só faz sentido reportar quando aplicamos um multiplier
        // sobre tabela pública (= multiplier < 1.0). Quando o contrato é
        // de "preços fixos contratados" (multiplier == 1.0 + has_discount),
        // mostramos "preços contratados" sem percentagem inventada.
        $discountPct = ($hasDiscount && $multiplier < 1.0)
            ? round((1 - $multiplier) * 100, 1)
            : 0;

        if ($hasDiscount) {
            $disclaimer = $multiplier < 1.0
                ? "Valor indicativo — exclui IVA, sobretaxa combustível e despesas adicionais. Desconto PartYard {$discountPct}% aplicado sobre tabela pública."
                : 'Valor indicativo — exclui IVA, sobretaxa combustível e despesas adicionais. Preços já contratados PartYard (Acordo Comercial PTDF6).';
        } else {
            $disclaimer = 'Valor indicativo — exclui IVA, sobretaxa combustível e despesas adicionais. **Tabela pública** — desconto PartYard ainda não configurado.';
        }

        return [
            'ok'             => true,
            'carrier'        => 'FedEx / TNT',
            'origin'         => $origin,
            'destination'    => $dest,
            'destination_pt' => FedExZones::PT_NAME[$dest] ?? $dest,
            'zone'           => $zoneNum,
            'zone_label'     => $zoneLabel,
            'service'        => $service,
            'service_label'  => FedExRates::SERVICE_LABELS[$service] ?? $service,
            'real_weight'    => round($weight, 2),
            'volumetric_kg'  => $volumetric,
            'billing_weight' => round($billing, 2),
            'currency'       => FedExRates::CURRENCY,
            'price_excl_vat' => round($price, 2),
            'base_rate'      => round($priced['base_rate'], 2),
            'breakpoint_kg'  => $priced['breakpoint_kg'],
            'overflow_kg'    => $priced['overflow_kg'],
            'overflow_rate'  => $priced['overflow_rate'],
            'effective_to'   => FedExRates::EFFECTIVE_TO,
            'contract'       => FedExRates::CONTRACT,
            'contract_code'  => FedExRates::CONTRACT_CODE,
            'has_discount'   => $hasDiscount,
            'discount_pct'   => $discountPct,
            'discount_label' => $hasDiscount
                ? FedExRates::CONTRACT_LABEL_PARTYARD
                : FedExRates::CONTRACT_LABEL_PUBLIC,
            'disclaimer'     => $disclaimer,
        ];
    }

    public function formatFedExQuote(array $q): string
    {
        if (!($q['ok'] ?? false)) {
            $msg = '❌ ' . ($q['error'] ?? 'Não consegui calcular.');
            if (!empty($q['hint'])) $msg .= "\n" . $q['hint'];
            return $msg;
        }

        // Discount badge sempre visível — line da estimativa
        $priceLine = '- **Preço estimado:** **'
            . number_format($q['price_excl_vat'], 2, ',', ' ') . ' €** (excl. IVA)';
        if ($q['has_discount']) {
            $priceLine .= $q['discount_pct'] > 0
                ? ' · *com desconto PartYard ' . $q['discount_pct'] . '%*'
                : ' · *tarifa contratada PartYard ' . $q['contract_code'] . '*';
        } else {
            $priceLine .= ' · *⚠ sem desconto PartYard (tabela pública)*';
        }

        $lines = [
            '📦 **Estimativa FedEx / TNT (' . $q['discount_label'] . ')**',
            '',
            '- **Rota:** ' . $q['origin'] . ' → ' . $q['destination']
                . ' (' . $q['destination_pt'] . ', ' . $q['zone_label'] . ')',
            '- **Serviço:** ' . $q['service_label'],
            '- **Peso faturável:** ' . $q['billing_weight'] . ' kg'
                . ($q['volumetric_kg'] > $q['real_weight']
                    ? ' *(volumétrico ' . $q['volumetric_kg'] . ' kg > real ' . $q['real_weight'] . ' kg)*'
                    : ''),
            $priceLine,
        ];

        if ($q['overflow_kg'] > 0) {
            $lines[] = '   ↳ base ' . number_format($q['base_rate'], 2, ',', ' ') . ' € até '
                     . $q['breakpoint_kg'] . ' kg + ' . $q['overflow_kg'] . ' kg × '
                     . number_format($q['overflow_rate'], 2, ',', ' ') . ' €/kg';
        }

        $lines[] = '';
        $lines[] = '_' . $q['disclaimer'] . '_';

        return implode("\n", $lines);
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
        $upsContract  = UpsRates::CONTRACT;
        $upsEffective = UpsRates::EFFECTIVE_TO;
        $fedExEffective = FedExRates::EFFECTIVE_TO;
        return <<<SKILL

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🚚 SKILL: LOGÍSTICA/PARTYARD — ESTIMATIVAS DE TRANSPORTE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Tens acesso a 2 transportadoras para estimativas de envio em EUR (excl. IVA):

 1) UPS — contrato PartYard {$upsContract}, válido até {$upsEffective}
    Serviços: Express Saver (PT→destino + receção origem→PT), Express,
    Expedited. Volumetric divisor = 5000 (L×W×H cm / 5000).
    Zonas: PT=1, ES=2, FR/DE/IT/BE/NL/LU=3, DK/IE/SE/FI/GR=4,
    PL/CZ/SK/HU=41, RO/BG/Balticos=42, GB=703/753, CH=51, NO=5,
    US=5/7, CA=6/7, BR=7/9, CN=8/10, AU=8, EAU/Israel=8/9.
    Limite peso real por pacote: 70 kg.
    Endpoint: ShippingRateService::quote()

 2) FedEx / TNT — tarifa pública 2026 (até {$fedExEffective})
    Serviços:
      · pt_domestic       — PT nacional (até 5kg base + adc/kg)
                            4 destinos: pt1 (continental Z1),
                            pt2 (continental Z2 = CP 5000-5999, 6050,
                            7000-8999), pt_m (Madeira), pt_a (Açores)
      · int_express       — internacional end-of-next-day, 9 zonas
      · int_economy       — internacional slower (~30% mais barato)
    Volumetric divisor PT continente: ×150 (peso vol = L×W×H em m × 150)
    Volumetric divisor PT ilhas:      ×250
    Volumetric divisor internacional: /5000 (igual UPS)
    Limite peso real: 40 kg por item, 3000 kg por envio (continente);
    150 kg/envio (ilhas).
    Zonas internacionais TNT (atenção: ≠ zonas UPS):
      1=Espanha Z1 cidades · 2=Espanha Z2 · 3=DE/FR/IT/IE/GB/PL/SE
      4=AT/BE-LU/CH/CZ/SK/HU/RO/BG/DK/NO/FI/GR/Balticos/CY/MT
      5=Albânia/Sérvia/Kosovo/Macedónia/Rússia/Bielorrússia/UA/Cazaq
      6=US/CA/BR · 7=CN/HK/JP/KR/SG/IN/TW/TH/MY/PH/VN/AE/Paquistão
      8=AU/NZ/ZA/EG/MA/MX/IL · 9=Resto (África, América Central/Sul, Pacífico)
    Endpoint: ShippingRateService::quoteFedEx()

━━━ COMO DECIDIR UPS vs FedEx ━━━

 · Spares URGENTES intra-EU + GB: ambos servem; UPS Express Saver
   costuma ser mais barato peso ≤30 kg.
 · Spares NÃO-críticos intra-EU: FedEx int_economy ~30% mais barato
   que UPS Express Saver.
 · PT → PT (domestic): UPS não tem contrato doméstico PartYard, usa
   sempre FedEx pt_domestic (5.92€ Z1 até 5kg).
 · Cargas pesadas (>30 kg, intercontinental): pede ambas e compara —
   ranges variam por zona.
 · 9:00 / 10:00 / 12:00 garantidos: FedEx tem (com surcharge fixo
   €8/€5/€2 <70kg). UPS não.
 · Mercadorias perigosas (DG / lithium / dry ice): ambos cobram extra;
   FedEx surcharge fixo €50 (DG) ou €5 (lithium).

━━━ OUTPUT FORMAT ━━━

Quando o utilizador pedir cotação, indica:
  - **Transportadora** + serviço
  - **Rota**: origem → destino + zona
  - **Peso faturável** (real ou volumétrico, o maior)
  - **Preço estimado** EUR (excl. IVA)
  - **Disclaimer**: indicativo, exclui combustível + sobretaxas

Se vale a pena, oferece **comparação UPS vs FedEx** lado-a-lado.

SKILL;
    }
}
