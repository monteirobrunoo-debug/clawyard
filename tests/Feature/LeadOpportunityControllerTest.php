<?php

namespace Tests\Feature;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /leads triage board — manager+ only, default view hides drafts,
 * status + assignment can be flipped via PATCH.
 */
class LeadOpportunityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function regularUser(): User
    {
        return User::factory()->create(['role' => 'user', 'is_active' => true]);
    }

    private function lead(int $score = 60, string $status = 'review'): LeadOpportunity
    {
        $run = AgentSwarmRun::create([
            'signal_type' => 'tender', 'signal_id' => uniqid('', true),
            'signal_hash' => uniqid('', true),
            'chain_name'  => 'tender_to_lead',
            'status'      => 'done',
        ]);
        return LeadOpportunity::create([
            'swarm_run_id' => $run->id,
            'title'        => 'T',
            'summary'      => 'S',
            'score'        => $score,
            'source_signal_type' => 'tender',
            'source_signal_id'   => '1',
            'status'       => $status,
        ]);
    }

    public function test_regular_user_cannot_access_leads(): void
    {
        $u = $this->regularUser();
        $this->actingAs($u)->get(route('leads.index'))->assertForbidden();
    }

    public function test_manager_sees_leads_excluding_drafts_and_discarded_by_default(): void
    {
        $mgr = $this->manager();
        $a = $this->lead(60, LeadOpportunity::STATUS_REVIEW);
        $b = $this->lead(20, LeadOpportunity::STATUS_DRAFT);
        $c = $this->lead(40, LeadOpportunity::STATUS_DISCARDED);

        $r = $this->actingAs($mgr)->get(route('leads.index'));
        $r->assertOk();
        $r->assertViewHas('leads', function ($paginator) use ($a, $b, $c) {
            $ids = collect($paginator->items())->pluck('id')->all();
            return in_array($a->id, $ids, true)
                && !in_array($b->id, $ids, true)
                && !in_array($c->id, $ids, true);
        });
    }

    public function test_status_filter_overrides_default_hide(): void
    {
        $mgr = $this->manager();
        $draft = $this->lead(20, LeadOpportunity::STATUS_DRAFT);

        $r = $this->actingAs($mgr)->get(route('leads.index', ['status' => 'draft']));
        $r->assertViewHas('leads', function ($paginator) use ($draft) {
            return collect($paginator->items())->pluck('id')->contains($draft->id);
        });
    }

    public function test_min_score_filter(): void
    {
        $mgr = $this->manager();
        $hi  = $this->lead(85);
        $lo  = $this->lead(45);

        $r = $this->actingAs($mgr)->get(route('leads.index', ['min_score' => 70]));
        $r->assertViewHas('leads', function ($paginator) use ($hi, $lo) {
            $ids = collect($paginator->items())->pluck('id')->all();
            return in_array($hi->id, $ids, true) && !in_array($lo->id, $ids, true);
        });
    }

    public function test_search_q_matches_title_summary_or_hint(): void
    {
        $mgr = $this->manager();
        $a = $this->lead(60);
        $a->update(['customer_hint' => 'OCEANPACT', 'title' => 'Alpha']);
        $b = $this->lead(60);
        $b->update(['title' => 'Beta', 'customer_hint' => 'Other']);

        $r = $this->actingAs($mgr)->get(route('leads.index', ['q' => 'oceanpact']));
        $r->assertViewHas('leads', function ($paginator) use ($a, $b) {
            $ids = collect($paginator->items())->pluck('id')->all();
            return in_array($a->id, $ids, true) && !in_array($b->id, $ids, true);
        });
    }

    public function test_patch_status_updates_and_stamps_contacted_at(): void
    {
        $mgr = $this->manager();
        $lead = $this->lead(75, LeadOpportunity::STATUS_CONFIDENT);

        $this->actingAs($mgr)
            ->patch(route('leads.update', $lead), ['status' => LeadOpportunity::STATUS_CONTACTED])
            ->assertRedirect();

        $fresh = $lead->fresh();
        $this->assertSame(LeadOpportunity::STATUS_CONTACTED, $fresh->status);
        $this->assertNotNull($fresh->contacted_at);
    }

    public function test_patch_assigned_user(): void
    {
        $mgr = $this->manager();
        $assignee = $this->regularUser();
        $lead = $this->lead();

        $this->actingAs($mgr)
            ->patch(route('leads.update', $lead), ['assigned_user_id' => $assignee->id])
            ->assertRedirect();

        $this->assertSame($assignee->id, $lead->fresh()->assigned_user_id);
    }
}
