<?php

namespace Tests\Feature;

use App\Models\PartnerWorkshop;
use App\Services\PartnerWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the agent-context-builder behaviour over partner_workshops:
 *
 *   • intent detection — generic chitchat ⇒ NO block injected.
 *   • port-name match — message mentioning a known port surfaces
 *     that port's partners.
 *   • term → coverage chip — "engine overhaul" routes to rows
 *     with prime_movers chip.
 *   • domain scoping — Marco (spares) and Vasco (repair) get
 *     disjoint default subsets when a row is exclusive to one
 *     domain, but BOTH receive rows tagged with both.
 *   • priority order — high_priority always ranks above prospect.
 *   • output shape — the rendered block carries verbatim phone /
 *     email so the agent can cite without paraphrasing.
 */
class PartnerWorkshopServiceTest extends TestCase
{
    use RefreshDatabase;

    private function partner(array $overrides = []): PartnerWorkshop
    {
        return PartnerWorkshop::create(array_merge([
            'port'           => 'Rotterdam',
            'company_name'   => 'Test Co',
            'category'       => 'Prime Movers / Engine OEM',
            'service_tokens' => ['engines'],
            'coverage_chips' => ['prime_movers' => true],
            'domains'        => [PartnerWorkshop::DOMAIN_SPARES],
            'phone'          => '+31 10 000 0000',
            'email'          => 'test@example.com',
            'priority'       => PartnerWorkshop::PRIORITY_PROSPECT,
            'is_active'      => true,
        ], $overrides));
    }

    public function test_chitchat_returns_null(): void
    {
        $this->partner();
        $svc = new PartnerWorkshopService();

        $this->assertNull(
            $svc->buildContextFor('Olá, tudo bem?', PartnerWorkshop::DOMAIN_SPARES),
            'Greeting is not partner-related; no block should be injected'
        );
    }

    public function test_port_name_in_message_triggers_lookup(): void
    {
        $this->partner(['port' => 'Singapore', 'company_name' => 'SG Marine', 'domains' => [PartnerWorkshop::DOMAIN_SPARES]]);
        $svc = new PartnerWorkshopService();

        $block = $svc->buildContextFor(
            'Preciso de fornecedor para overhaul em Singapore',
            PartnerWorkshop::DOMAIN_SPARES
        );
        $this->assertNotNull($block);
        $this->assertStringContainsString('SG Marine', $block);
        $this->assertStringContainsString('Singapore', $block);
    }

    public function test_engine_term_routes_to_prime_movers_chip(): void
    {
        // Two partners in the same port — only one has prime_movers chip.
        $this->partner([
            'company_name'   => 'Engine Specialist',
            'coverage_chips' => ['prime_movers' => true],
            'priority'       => PartnerWorkshop::PRIORITY_HIGH,
        ]);
        $this->partner([
            'company_name'   => 'Generic Workshop',
            'coverage_chips' => ['prime_movers' => false, 'electrical' => true],
        ]);

        $svc   = new PartnerWorkshopService();
        $block = $svc->buildContextFor('engine overhaul Rotterdam', PartnerWorkshop::DOMAIN_SPARES);

        $this->assertStringContainsString('Engine Specialist', $block);
    }

    public function test_domain_scoping_excludes_repair_only_rows_for_marco(): void
    {
        // Repair-only partner.
        $this->partner([
            'company_name' => 'Hull Repair Yard',
            'category'     => 'Shipyard / Full-Service Repair',
            'domains'      => [PartnerWorkshop::DOMAIN_REPAIR],
        ]);
        // Spares partner.
        $this->partner([
            'company_name' => 'Wartsila Service',
            'domains'      => [PartnerWorkshop::DOMAIN_SPARES],
        ]);

        $svc   = new PartnerWorkshopService();
        $block = $svc->buildContextFor('preciso de oficina em Rotterdam', PartnerWorkshop::DOMAIN_SPARES);

        $this->assertNotNull($block);
        $this->assertStringContainsString('Wartsila Service', $block);
        $this->assertStringNotContainsString('Hull Repair Yard', $block);
    }

    public function test_dual_domain_partner_visible_to_both_agents(): void
    {
        $this->partner([
            'company_name' => 'Damen Both',
            'category'     => 'Shipyard / Full-Service Repair',
            'domains'      => [PartnerWorkshop::DOMAIN_SPARES, PartnerWorkshop::DOMAIN_REPAIR],
        ]);

        $svc = new PartnerWorkshopService();
        foreach ([PartnerWorkshop::DOMAIN_SPARES, PartnerWorkshop::DOMAIN_REPAIR] as $d) {
            $block = $svc->buildContextFor('oficina em Rotterdam', $d);
            $this->assertNotNull($block, "Should produce a block for domain={$d}");
            $this->assertStringContainsString('Damen Both', $block);
        }
    }

    public function test_high_priority_ranks_above_prospect(): void
    {
        $this->partner([
            'company_name' => 'Aaa Prospect',
            'priority'     => PartnerWorkshop::PRIORITY_PROSPECT,
        ]);
        $this->partner([
            'company_name' => 'Zzz High',
            'priority'     => PartnerWorkshop::PRIORITY_HIGH,
        ]);

        $svc   = new PartnerWorkshopService();
        $block = $svc->buildContextFor('engine Rotterdam', PartnerWorkshop::DOMAIN_SPARES);

        $aaa = strpos($block, 'Aaa Prospect');
        $zzz = strpos($block, 'Zzz High');
        $this->assertNotFalse($zzz);
        $this->assertNotFalse($aaa);
        $this->assertLessThan($aaa, $zzz, 'high_priority must precede prospect in the rendered block');
    }

    public function test_inactive_rows_are_excluded(): void
    {
        $this->partner(['company_name' => 'Active Co']);
        $this->partner(['company_name' => 'Disabled Co', 'is_active' => false]);

        $svc   = new PartnerWorkshopService();
        $block = $svc->buildContextFor('engine Rotterdam', PartnerWorkshop::DOMAIN_SPARES);
        $this->assertStringContainsString('Active Co', $block);
        $this->assertStringNotContainsString('Disabled Co', $block);
    }

    public function test_phone_and_email_are_rendered_verbatim(): void
    {
        $this->partner([
            'company_name' => 'Cite Me',
            'phone'        => '+44 20 1234 5678',
            'email'        => 'cite-me@example.com',
        ]);

        $svc   = new PartnerWorkshopService();
        $block = $svc->buildContextFor('engine Rotterdam', PartnerWorkshop::DOMAIN_SPARES);
        $this->assertStringContainsString('+44 20 1234 5678', $block);
        $this->assertStringContainsString('cite-me@example.com', $block);
    }
}
