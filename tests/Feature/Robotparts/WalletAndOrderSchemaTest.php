<?php

namespace Tests\Feature\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1 — schema lock-in for the robot-parts marketplace.
 *
 *   • Wallet defaults to zero, lazy-creates via forAgent()
 *   • adjust() handles credit + debit and never goes below zero
 *   • canAfford() is a pre-check
 *   • PartOrder lifecycle constants exist + status labels render
 *   • appendCommittee() builds the deliberation log incrementally
 */
class WalletAndOrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_lazy_creates_with_zero_balance(): void
    {
        $w = AgentWallet::forAgent('sales');
        $this->assertSame('sales', $w->agent_key);
        $this->assertSame(0.0, (float) $w->balance_usd);
        $this->assertSame(0.0, (float) $w->lifetime_earned_usd);
        $this->assertSame(0.0, (float) $w->lifetime_spent_usd);

        // Calling again returns the SAME row, not a new one.
        AgentWallet::forAgent('sales');
        $this->assertSame(1, AgentWallet::count());
    }

    public function test_credit_adds_to_balance_and_lifetime_earned(): void
    {
        $w = AgentWallet::forAgent('vessel');
        $newBalance = $w->adjust(2.50);

        $this->assertEqualsWithDelta(2.50, $newBalance, 0.0001);
        $this->assertEqualsWithDelta(2.50, (float) $w->balance_usd, 0.0001);
        $this->assertEqualsWithDelta(2.50, (float) $w->lifetime_earned_usd, 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $w->lifetime_spent_usd, 0.0001);
    }

    public function test_debit_subtracts_balance_and_increments_lifetime_spent(): void
    {
        $w = AgentWallet::forAgent('crm');
        $w->adjust(5.00);
        $w->adjust(-1.20);

        $this->assertEqualsWithDelta(3.80, (float) $w->balance_usd, 0.0001);
        $this->assertEqualsWithDelta(5.00, (float) $w->lifetime_earned_usd, 0.0001);
        $this->assertEqualsWithDelta(1.20, (float) $w->lifetime_spent_usd, 0.0001);
    }

    public function test_balance_never_goes_below_zero_on_overdraft(): void
    {
        $w = AgentWallet::forAgent('research');
        $w->adjust(1.00);
        // Try to spend $5 with only $1 in the wallet.
        $w->adjust(-5.00);

        $this->assertSame(0.0, (float) $w->balance_usd,
            'overdraft must clamp to 0, not go negative');
        // But lifetime_spent records the full intended debit so the
        // log isn't lying about what was attempted.
        $this->assertEqualsWithDelta(5.00, (float) $w->lifetime_spent_usd, 0.0001);
    }

    public function test_can_afford_returns_correct_pre_check(): void
    {
        $w = AgentWallet::forAgent('engineer');
        $w->adjust(3.50);

        $this->assertTrue($w->canAfford(3.50));
        $this->assertTrue($w->canAfford(1.00));
        $this->assertFalse($w->canAfford(3.51));
    }

    public function test_wallet_resolves_meta_from_agent_catalog(): void
    {
        $w = AgentWallet::forAgent('sales');
        $meta = $w->meta();
        $this->assertNotNull($meta);
        $this->assertSame('Marco Sales', $meta['name']);
    }

    public function test_unknown_agent_meta_is_null_but_wallet_still_works(): void
    {
        $w = AgentWallet::forAgent('not-a-real-agent');
        $this->assertNull($w->meta());
        $w->adjust(1.0);
        $this->assertEqualsWithDelta(1.0, (float) $w->balance_usd, 0.0001);
    }

    // ── PartOrder ───────────────────────────────────────────────────────────

    public function test_order_default_status_is_committee(): void
    {
        $o = PartOrder::create(['agent_key' => 'sales', 'name' => 'Test arm', 'cost_usd' => 1.50]);
        $this->assertSame(PartOrder::STATUS_COMMITTEE, $o->status);
    }

    public function test_order_lifecycle_status_constants_have_labels(): void
    {
        $statuses = [
            PartOrder::STATUS_COMMITTEE,
            PartOrder::STATUS_SEARCHING,
            PartOrder::STATUS_PURCHASED,
            PartOrder::STATUS_DESIGNING,
            PartOrder::STATUS_STL_READY,
            PartOrder::STATUS_CNC_QUEUED,
            PartOrder::STATUS_COMPLETED,
            PartOrder::STATUS_CANCELLED,
        ];
        foreach ($statuses as $s) {
            $o = new PartOrder(['agent_key' => 'x', 'name' => 'p', 'status' => $s]);
            $label = $o->statusLabel();
            $this->assertNotEmpty($label, "status {$s} must have a label");
            $this->assertNotSame($s, $label, "label must differ from raw status key");
        }
    }

    public function test_append_committee_builds_log_incrementally(): void
    {
        $o = PartOrder::create(['agent_key' => 'sales', 'name' => 'Robot arm', 'cost_usd' => 5]);
        $o->appendCommittee('research', 'helper', 'I think a 6-DOF servo arm fits the budget');
        $o->appendCommittee('vessel',   'helper', 'Marine grade aluminium would last 20 years');
        $o->appendCommittee('sales',    'buyer',  'Going with the 6-DOF — closing my deals visually beats durability');

        $log = $o->fresh()->committee_log;
        $this->assertCount(3, $log);
        $this->assertSame('sales', $log[2]['agent_key']);
        $this->assertSame('buyer', $log[2]['role']);
        $this->assertNotEmpty($log[0]['at']);
    }

    public function test_order_links_back_to_wallet(): void
    {
        AgentWallet::forAgent('sales');
        $o = PartOrder::create(['agent_key' => 'sales', 'name' => 'p', 'cost_usd' => 1]);
        $this->assertSame('sales', $o->wallet()->first()->agent_key);
    }

    public function test_wallet_lists_its_orders(): void
    {
        $w = AgentWallet::forAgent('vessel');
        PartOrder::create(['agent_key' => 'vessel', 'name' => 'A', 'cost_usd' => 1]);
        PartOrder::create(['agent_key' => 'vessel', 'name' => 'B', 'cost_usd' => 2]);
        $this->assertSame(2, $w->orders()->count());
    }

    public function test_stl_download_url_is_null_until_path_is_set(): void
    {
        $o = PartOrder::create(['agent_key' => 'x', 'name' => 'p', 'cost_usd' => 1]);
        $this->assertNull($o->stlDownloadUrl());
    }
}
