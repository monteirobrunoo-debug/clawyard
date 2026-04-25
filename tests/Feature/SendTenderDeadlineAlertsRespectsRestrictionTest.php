<?php

namespace Tests\Feature;

use App\Mail\TenderDeadlineAlert;
use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks down: the 24h deadline-alert email must NOT be sent for a
 * tender whose source or status the assigned collaborator is no
 * longer authorised to see.
 *
 * Why: a tender may have been assigned to a collaborator BEFORE the
 * admin restricted that collaborator's allowed_sources /
 * allowed_statuses. Without this guard the dashboard would correctly
 * hide the row but the inbox would still receive an email about it
 * — the same shape of leak that the scopeForUser fix closed.
 *
 * The skip stamps `deadline_alert_sent_at` so the row isn't
 * re-evaluated on the next hourly tick (don't accumulate idle work).
 */
class SendTenderDeadlineAlertsRespectsRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function tenderDueIn24h(int $collabId, string $source = 'nspa', ?string $status = null): Tender
    {
        return Tender::create([
            'source'                   => $source,
            'reference'                => 'REF-'.uniqid(),
            'title'                    => 'Concurso de teste',
            'status'                   => $status ?? Tender::STATUS_PENDING,
            'deadline_at'              => now()->addHours(24),
            'assigned_collaborator_id' => $collabId,
        ]);
    }

    public function test_alert_is_skipped_when_source_is_restricted(): void
    {
        Mail::fake();

        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = new TenderCollaborator();
        $c->name = 'C'; $c->normalized_name = 'c';
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_sources = ['acingov'];   // NSPA blocked
        $c->save();

        $t = $this->tenderDueIn24h($c->id, source: 'nspa');

        $this->artisan('tenders:send-deadline-alerts')
            ->expectsOutputToContain('skipped (collaborator restricted from source=nspa')
            ->assertExitCode(0);

        Mail::assertNotSent(TenderDeadlineAlert::class);
        $this->assertNotNull($t->fresh()->deadline_alert_sent_at,
            'Stamped to prevent the next hourly tick from re-evaluating');
    }

    public function test_alert_is_skipped_when_status_is_restricted(): void
    {
        Mail::fake();

        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = new TenderCollaborator();
        $c->name = 'C'; $c->normalized_name = 'c';
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_statuses = [Tender::STATUS_EM_TRATAMENTO];   // PENDING blocked
        $c->save();

        $t = $this->tenderDueIn24h($c->id, status: Tender::STATUS_PENDING);

        $this->artisan('tenders:send-deadline-alerts')
            ->expectsOutputToContain('status=pending')
            ->assertExitCode(0);

        Mail::assertNotSent(TenderDeadlineAlert::class);
        $this->assertNotNull($t->fresh()->deadline_alert_sent_at);
    }

    public function test_alert_is_sent_when_source_and_status_are_within_whitelist(): void
    {
        Mail::fake();

        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = new TenderCollaborator();
        $c->name = 'C'; $c->normalized_name = 'c';
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_sources  = ['nspa'];
        $c->allowed_statuses = [Tender::STATUS_PENDING];
        $c->save();

        $t = $this->tenderDueIn24h($c->id, source: 'nspa', status: Tender::STATUS_PENDING);

        $this->artisan('tenders:send-deadline-alerts')->assertExitCode(0);

        Mail::assertSent(TenderDeadlineAlert::class, fn(TenderDeadlineAlert $m) =>
            $m->hasTo($u->email)
        );
        $this->assertNotNull($t->fresh()->deadline_alert_sent_at);
    }

    public function test_unrestricted_collaborator_receives_alert_as_before(): void
    {
        Mail::fake();

        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = new TenderCollaborator();
        $c->name = 'C'; $c->normalized_name = 'c';
        $c->email = $u->email; $c->is_active = true;
        // Both whitelists null = no restriction.
        $c->save();

        $t = $this->tenderDueIn24h($c->id);

        $this->artisan('tenders:send-deadline-alerts')->assertExitCode(0);

        Mail::assertSent(TenderDeadlineAlert::class);
    }
}
