<?php

namespace App\Services;

/**
 * PartYardProfileService
 *
 * Single source of truth for all PartYard / HP-Group company data.
 * Used by all agents, the public API endpoint, and the BriefingAgent.
 */
class PartYardProfileService
{
    // ─── Full structured profile ───────────────────────────────────────────
    public static function profile(): array
    {
        return [
            'company'      => 'PartYard Marine',
            'legal_name'   => 'PartYard LDA',
            'website'      => 'https://www.partyard.eu',
            'parent'       => 'HP-Group (www.hp-group.org)',
            'partner'      => 'COGEMA (fundada em 1959, raízes marítimas Ibéricas)',
            'headquarters' => 'Setúbal, Portugal',
            'description'  => 'Empresa especialista em peças sobressalentes marítimas, logística de frotas e serviços de engenharia, com operações mundiais. Fornecedor NATO-certificado.',

            'certifications' => [
                'ISO 9001:2015' => 'Sistema de Gestão da Qualidade',
                'AS:9120'       => 'Qualidade Aeroespacial & Defesa',
                'NCAGE P3527'   => 'Fornecedor NATO-certificado (Allied Command Operations)',
            ],

            'offices' => [
                ['city' => 'Setúbal',         'country' => 'Portugal',        'flag' => '🇵🇹', 'type' => 'HQ'],
                ['city' => 'Texas',            'country' => 'USA',             'flag' => '🇺🇸', 'type' => 'Escritório'],
                ['city' => 'Dulles',           'country' => 'USA',             'flag' => '🇺🇸', 'type' => 'Escritório'],
                ['city' => 'Dorset',           'country' => 'Reino Unido',     'flag' => '🇬🇧', 'type' => 'Escritório'],
                ['city' => 'Rio de Janeiro',   'country' => 'Brasil',          'flag' => '🇧🇷', 'type' => 'Escritório'],
                ['city' => 'Kokstad',          'country' => 'Noruega',         'flag' => '🇳🇴', 'type' => 'Escritório'],
            ],

            'brands' => [
                'MTU'              => ['category' => 'motores',    'description' => 'Motores diesel marítimos e industriais de alta performance. Series 2000, 4000, 8000, 396.'],
                'Caterpillar (CAT)'=> ['category' => 'motores',    'description' => 'Motores de propulsão marítima e geradores. Série C e 3500.'],
                'MAK'              => ['category' => 'motores',    'description' => 'Motores diesel marítimos de velocidade média. M20, M25, M32, M43.'],
                'Jenbacher'        => ['category' => 'motores',    'description' => 'Motores a gás e sistemas de cogeração. Série J.'],
                'Cummins'          => ['category' => 'motores',    'description' => 'Motores diesel marítimos e industriais.'],
                'Wärtsilä'         => ['category' => 'motores',    'description' => 'Sistemas de propulsão e motores marítimos.'],
                'MAN'              => ['category' => 'motores',    'description' => 'Motores diesel de 2 e 4 tempos para navios.'],
                'SKF'              => ['category' => 'vedantes',   'description' => 'Vedantes SternTube e rolamentos para aplicações marítimas.'],
                'Schottel'         => ['category' => 'propulsão',  'description' => 'SRP (Rudder Propeller), STT (Transverse Thruster), STP (Pump Jet).'],
                'Cogema'           => ['category' => 'parceiro',   'description' => 'Parceiro técnico marítimo Ibérico (est. 1959).'],
            ],

            'product_categories' => [
                'Motores Diesel & Gás', 'Caldeiras', 'Compressores', 'Electrónica Marítima',
                'Equipamento de Combustível', 'Permutadores de Calor', 'Hélices',
                'Bombas', 'Separadores', 'Guindastes & Winches', 'Lubrificantes',
                'Vedantes SternTube', 'Rolamentos', 'Filtros', 'Turbos',
            ],

            'services' => [
                'Spare Parts Supply'     => 'Peças OEM e aftermarket, procurement global',
                'Emergency Supply'       => 'Sourcing emergência 24/7, entrega mundial em 24–72h',
                'Engineering Services'   => 'Suporte técnico, integração de sistemas, design naval',
                'Fleet Maintenance'      => 'Suporte a manutenção planeada, monitorização de condição',
                'OEM Distribution'       => 'Distribuição autorizada das principais marcas marítimas',
                'Defense Supply'         => 'Fornecimento NATO-certificado para defesa e aeroespacial',
                'Technical Consultancy'  => 'Consultoria técnica especializada para frotas marítimas',
            ],

            'sectors' => [
                'Naval & Militar', 'Offshore & Oil & Gas', 'Shipping Comercial',
                'Embarcações de Pesca', 'Ferry & Passageiros', 'Infraestrutura Portuária', 'Industrial',
            ],

            'social' => [
                'facebook' => 'https://www.facebook.com/partyard.eu',
                'linkedin' => 'https://www.linkedin.com/company/partyard-marine',
                'youtube'  => 'https://www.youtube.com/channel/UCcfsLx5s7z0tjosujfuB_lw',
            ],

            'group_companies' => [
                'HP-Group'              => ['url' => 'www.hp-group.org',         'focus' => 'Multinacional mãe: Space, Marine, Railway, Defence, Aviation, Industry'],
                'PartYard Military'     => ['url' => 'www.partyardmilitary.com', 'focus' => 'Defesa & Aeroespacial, supply chain NATO, plataformas militares, integração Cisco'],
                'PartYard Defense'      => ['url' => null,                       'focus' => 'Sistemas OEM para plataformas militares'],
                'SETQ'                  => ['url' => null,                       'focus' => 'Cibersegurança e soluções AI'],
                'IndYard'               => ['url' => null,                       'focus' => 'Soluções de workforce e RH'],
                'TekYard'               => ['url' => null,                       'focus' => 'Serviços tecnológicos e integração de sistemas'],
                'HSM Portugal'          => ['url' => null,                       'focus' => 'Integração de sistemas'],
                'Viridis Ocean Shipping'=> ['url' => null,                       'focus' => 'Logística marítima sustentável'],
            ],

            'website_issues' => [
                ['priority' => '🔴', 'issue' => 'Página /products retorna 404 — página mais crítica do site'],
                ['priority' => '🔴', 'issue' => 'Conteúdo renderizado via JavaScript — invisível para Google (SEO zero)'],
                ['priority' => '🔴', 'issue' => 'Sem CTA "Pedir Cotação" / "Request Quote" visível'],
                ['priority' => '🟠', 'issue' => 'Sem páginas dedicadas por marca (/brands/mtu, /brands/skf, etc.)'],
                ['priority' => '🟠', 'issue' => 'Certificações (ISO, NATO, AS9120) não visíveis na homepage'],
                ['priority' => '🟠', 'issue' => 'Sem números de prova social (países, peças entregues, tempo de entrega)'],
                ['priority' => '🟡', 'issue' => 'Sem blog técnico para SEO de longo prazo'],
                ['priority' => '🟡', 'issue' => 'Sem WhatsApp Business para contacto de emergência'],
                ['priority' => '🟡', 'issue' => 'Mapa de escritórios sem interactividade'],
                ['priority' => '🟡', 'issue' => 'Sem schema markup (Product, Organization, LocalBusiness)'],
                ['priority' => '🟢', 'issue' => 'Versão inglesa inconsistente (partes em português quando em EN)'],
            ],

            'competitors' => [
                'Wärtsilä Parts'   => 'OEM directo, preços premium, lead times longos',
                'MacGregor'        => 'Forte em deck equipment, fraco em motores',
                'MTU Onsite'       => 'OEM directo MTU, sem multi-marca',
                'Rolls-Royce Marine'=> 'Naval premium, menos acessível a frotas comerciais',
                'Trident'          => 'Mercado UK, sem presença ibérica forte',
            ],

            'emergency_contact' => [
                'service' => 'Emergency Parts 24/7',
                'whatsapp' => 'Disponível via WhatsApp Business',
                'delivery' => '24–72h entrega mundial',
                'rfq'      => 'https://www.partyard.eu/contact',
            ],
        ];
    }

    // ─── Plain-text context block for agent system prompts ─────────────────
    public static function toPromptContext(): string
    {
        $p = self::profile();

        $offices = implode(' | ', array_map(
            fn($o) => "{$o['flag']} {$o['city']}, {$o['country']} ({$o['type']})",
            $p['offices']
        ));

        $brands = implode(', ', array_keys($p['brands']));

        $certs = implode(' | ', array_map(
            fn($cert, $desc) => "{$cert} [{$desc}]",
            array_keys($p['certifications']),
            array_values($p['certifications'])
        ));

        $services = implode(', ', array_keys($p['services']));
        $sectors  = implode(', ', $p['sectors']);
        $cats     = implode(', ', $p['product_categories']);

        return <<<CONTEXT
PARTYARD MARINE — PERFIL COMPLETO DA EMPRESA:
- Nome Legal: {$p['legal_name']} | Website: {$p['website']} | HQ: {$p['headquarters']}
- Grupo: {$p['parent']} | Parceiro histórico: {$p['partner']}
- Descrição: {$p['description']}
- Escritórios (6 países): {$offices}
- Certificações: {$certs}
- Marcas OEM: {$brands}
- Categorias de produto: {$cats}
- Serviços: {$services}
- Sectores: {$sectors}
- LinkedIn: {$p['social']['linkedin']}
- Emergências: {$p['emergency_contact']['delivery']} — {$p['emergency_contact']['rfq']}
CONTEXT;
    }

    // ─── JSON for public API endpoint ───────────────────────────────────────
    public static function toPublicJson(): array
    {
        $p = self::profile();
        return [
            'company'        => $p['company'],
            'legal_name'     => $p['legal_name'],
            'website'        => $p['website'],
            'headquarters'   => $p['headquarters'],
            'parent_group'   => $p['parent'],
            'description'    => $p['description'],
            'certifications' => array_keys($p['certifications']),
            'offices'        => array_map(
                fn($o) => "{$o['city']}, {$o['country']}",
                $p['offices']
            ),
            'brands'         => array_keys($p['brands']),
            'product_categories' => $p['product_categories'],
            'services'       => array_keys($p['services']),
            'sectors'        => $p['sectors'],
            'social'         => $p['social'],
            'group_companies'=> array_map(fn($g) => $g['focus'], $p['group_companies']),
            'emergency'      => $p['emergency_contact'],
            'generated_at'   => now()->toIso8601String(),
        ];
    }
}
