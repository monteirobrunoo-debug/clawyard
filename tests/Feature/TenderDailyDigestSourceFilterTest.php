<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use App\Services\TenderDailyDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down: the daily digest a regular user receives must NOT
 * include tenders from sources they aren't authorised to see.
 *
 * Why this test exists: when source-restriction was added (commit
 * f138eed) the runtime scope on /tenders honoured it, but the digest
 * runs on its own service path. Both paths happen to call
 * Tender::scopeForUser internally, so a single regression in the
 * scope would silently leak via email — same kind of bug as the
 * Catarina/Mónica leak, just delivered to inbox. Lock it.
 *
 * Manager digests are intentionally NOT subject to allowed_sources:
 * managers oversee everyone (gate `tenders.view-all`). That decision
 * is also pinned by a test in this file so a future "filter for
 * managers too" change is a deliberate one.
 */
class TenderDailyDigestSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    private TenderDailyDigestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenderDailyDigestService();
    }

    private function tender(string $source, int $collabId): Tender
    {
        return Tender::create([
            'source'                   => $source,
            'reference'                => 'REF-'.$source.'-'.$collabId.'-'.uniqid(),
            'title'                    => "Concurso {$source}",
            'status'                   => Tender::STATUS_PENDING,
            // Far-future deadline so the urgency filter never excludes
            // these from the actionable bucket.
            'deadline_at'              => now()->addDays(7),
            'assigned_collaborator_id' => $collabId,
        ]);
    }

    public function test_user_digest_excludes_sources_outside_allowed_list(): void
    {
        $u = User::factory()->create([
            'email'     => 'user@hp-group.org',
            'role'      => 'user',
            'is_active' => true,
        ]);

        $c = new TenderCollaborator();
        $c->name            = 'User';
        $c->normalized_name = TenderCollaborator::normalize('User');
        $c->email           = $u->email;
        $c->is_active       = true;
        $c->allowed_sources = ['nspa'];
        $c->save();

        $this->tender('nspa',    $c->id);
        $this->tender('acingov', $c->id);
        $this->tender('sam_gov', $c->id);

        $recipients = $this->svc->buildRecipients();
        $forUser    = collect($recipients)->firstWhere('user.id', $u->id);

        $this->assertNotNull($forUser, 'User must receive a digest');
        $this->assertSame(1, $forUser['total'], 'Only the NSPA tender belongs in the bucket');

        $sources = collect($forUser['groups'])
            ->flatMap(fn($bucket) => $bucket->pluck('source'))
            ->unique()
            ->values()
            ->all();
        $this->assertSame(['nspa'], $sources);
    }

    public function test_user_with_blocked_all_gets_no_digest(): void
    {
        $u = User::factory()->create([
            'email'     => 'blocked@hp-group.org',
            'role'      => 'user',
            'is_active' => true,
        ]);

        $c = new TenderCollaborator();
        $c->name            = 'Blocked';
        $c->normalized_name = TenderCollaborator::normalize('Blocked');
        $c->email           = $u->email;
        $c->is_active       = true;
        $c->allowed_sources = [];   // explicitly blocked from every source
        $c->save();

        $this->tender('nspa',    $c->id);
        $this->tender('acingov', $c->id);

        $recipients = $this->svc->buildRecipients();
        $forUser    = collect($recipients)->firstWhere('user.id', $u->id);

        // Empty bucket → suppressed by buildRecipients.
        $this->assertNull($forUser, 'Fully-blocked user must not receive a digest at all');
    }

    public function test_manager_digest_is_not_filtered_by_allowed_sources(): void
    {
        // Pin the deliberate decision: managers see EVERYTHING in the
        // digest, regardless of their own collaborator row's
        // allowed_sources. They oversee the whole pipeline.
        $mgr = User::factory()->create([
            'email'     => 'mgr@hp-group.org',
            'role'      => 'manager',
            'is_active' => true,
        ]);

        $c = new TenderCollaborator();
        $c->name            = 'Manager';
        $c->normalized_name = TenderCollaborator::normalize('Manager');
        $c->email           = $mgr->email;
        $c->is_active       = true;
        $c->allowed_sources = ['nspa'];   // restricted as a collaborator
        $c->save();

        // Tenders from multiple sources, none assigned to the manager —
        // the manager view is "everyone's stuff".
        $other = new TenderCollaborator();
        $other->name = 'Outro';
        $other->normalized_name = 'outro';
        $other->is_active = true;
        $other->save();

        $this->tender('nspa',    $other->id);
        $this->tender('acingov', $other->id);
        $this->tender('sam_gov', $other->id);

        $recipients = $this->svc->buildRecipients();
        $forMgr     = collect($recipients)->firstWhere('user.id', $mgr->id);

        $this->assertNotNull($forMgr, 'Manager must receive the supervisor digest');
        $this->assertSame('manager', $forMgr['role']);
        $this->assertSame(3, $forMgr['total'], 'Manager must see all sources, ignoring own allowed_sources');
    }
}
