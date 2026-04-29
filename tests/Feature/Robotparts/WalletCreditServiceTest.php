<?php

namespace Tests\Feature\Robotparts;

use App\Models\AgentMetric;
use App\Models\AgentWallet;
use App\Services\Robotparts\WalletCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D2 — wallet credit cron correctness.
 *
 *   • Formula: $0.50/lead_won + $0.05/signal + $0.10/up - $0.05/down
 *   • Idempotent: re-run within same day = 0 credit
 *   • Delta only: only NEW activity since last run gets credited
 *   • Snapshot: last_credit_basis captures metric values at credit time
 *   • Failure isolation: one bad agent doesn't block the others
 */
class WalletCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_credit_pays_full_lifetime_metrics(): void
    {
        AgentMetric::create([
            'agent_key'         => 'sales',
            'leads_won'         => 4,           // 4 × $0.50 = $2.00
            'signals_processed' => 20,          // 20 × $0.05 = $1.00
            'thumbs_up'         => 5,           // 5 × $0.10 = $0.50
            'thumbs_down'       => 0,
        ]);

        $summary = (new WalletCreditService())->run();

        $this->assertSame(1, $summary['agents_credited']);
        $this->assertEqualsWithDelta(3.50, $summary['total_credited'], 0.0001);

        $w = AgentWallet::find('sales');
        $this->assertEqualsWithDelta(3.50, (float) $w->balance_usd, 0.0001);
        $this->assertEqualsWithDelta(3.50, (float) $w->lifetime_earned_usd, 0.0001);
        $this->assertNotNull($w->last_credit_at);
        $this->assertSame(4, $w->last_credit_basis['leads_won']);
        $this->assertSame(20, $w->last_credit_basis['signals_processed']);
    }

    public function test_idempotent_second_run_credits_nothing_new(): void
    {
        AgentMetric::create([
            'agent_key' => 'vessel',
            'leads_won' => 2,
        ]);

        (new WalletCreditService())->run();
        $balanceAfterFirst = (float) AgentWallet::find('vessel')->balance_usd;

        // Second run — no metric change, must credit 0.
        $second = (new WalletCreditService())->run();

        $this->assertSame(0, $second['agents_credited']);
        $this->assertEqualsWithDelta(0.0, $second['total_credited'], 0.0001);
        $this->assertEqualsWithDelta(
            $balanceAfterFirst,
            (float) AgentWallet::find('vessel')->balance_usd,
            0.0001
        );
    }

    public function test_delta_credit_only_pays_for_new_activity(): void
    {
        $m = AgentMetric::create([
            'agent_key' => 'crm',
            'leads_won' => 1,
        ]);

        // First run pays $0.50.
        (new WalletCreditService())->run();
        $this->assertEqualsWithDelta(0.50, (float) AgentWallet::find('crm')->balance_usd, 0.0001);

        // Bump the metric to 4 won (delta = 3 new wins).
        $m->leads_won = 4;
        $m->save();

        $summary = (new WalletCreditService())->run();
        $this->assertEqualsWithDelta(1.50, $summary['total_credited'], 0.0001,
            'delta credit must pay only the 3 NEW wins (× $0.50 = $1.50)');
        $this->assertEqualsWithDelta(2.00, (float) AgentWallet::find('crm')->balance_usd, 0.0001);
    }

    public function test_metric_reset_does_not_clawback_from_wallet(): void
    {
        // Defensive: if someone manually zeroes agent_metrics, the
        // wallet must NOT lose credit. We never go negative.
        $m = AgentMetric::create(['agent_key' => 'research', 'leads_won' => 10]);
        (new WalletCreditService())->run();

        // Some admin somehow zeroes the metric.
        $m->leads_won = 0;
        $m->save();

        $balanceBefore = (float) AgentWallet::find('research')->balance_usd;
        (new WalletCreditService())->run();

        $this->assertEqualsWithDelta(
            $balanceBefore,
            (float) AgentWallet::find('research')->balance_usd,
            0.0001,
            'reset metric must not deduct already-credited earnings'
        );
    }

    public function test_thumbs_down_alone_does_not_create_negative_credit(): void
    {
        // An agent with ONLY thumbs_down (no positive activity) is never
        // CREDITED (delta clamped to 0 by adjust()), but the snapshot
        // still updates so future rate changes don't double-pay.
        AgentMetric::create([
            'agent_key'   => 'support',
            'thumbs_down' => 5,
        ]);

        (new WalletCreditService())->run();
        $this->assertEqualsWithDelta(0.0, (float) AgentWallet::find('support')->balance_usd, 0.0001);
    }

    public function test_summary_lists_per_agent_breakdown(): void
    {
        AgentMetric::create(['agent_key' => 'sales',  'leads_won' => 2]);   // $1.00
        AgentMetric::create(['agent_key' => 'vessel', 'leads_won' => 1]);   // $0.50
        AgentMetric::create(['agent_key' => 'crm']);                         // $0 — skipped

        $summary = (new WalletCreditService())->run();

        $this->assertSame(3, $summary['agents_processed']);
        $this->assertSame(2, $summary['agents_credited']);
        $this->assertEqualsWithDelta(1.50, $summary['total_credited'], 0.0001);
        $this->assertArrayHasKey('sales',  $summary['per_agent']);
        $this->assertArrayHasKey('vessel', $summary['per_agent']);
        $this->assertArrayNotHasKey('crm', $summary['per_agent']);
    }

    public function test_artisan_command_runs_and_reports(): void
    {
        AgentMetric::create(['agent_key' => 'sales', 'leads_won' => 2]);

        $this->artisan('agents:credit-wallets')
             ->expectsOutputToContain('1/1 agents credited')
             ->assertSuccessful();

        // Side effect — wallet got the credit, command ran for real.
        $this->assertEqualsWithDelta(
            1.00,
            (float) AgentWallet::find('sales')->balance_usd,
            0.0001
        );
    }

    public function test_thumbs_up_credits_correctly(): void
    {
        AgentMetric::create([
            'agent_key' => 'engineer',
            'thumbs_up' => 7,        // 7 × $0.10 = $0.70
        ]);

        (new WalletCreditService())->run();
        $this->assertEqualsWithDelta(0.70, (float) AgentWallet::find('engineer')->balance_usd, 0.0001);
    }
}
