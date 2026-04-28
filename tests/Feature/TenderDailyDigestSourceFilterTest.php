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

    public function test_manager_digest_only_lists_tenders_assigned_to_them(): void
    {
        // Behaviour change 2026-04-27: managers no longer receive every
        // active tender in the daily digest. They only get tenders
        // ACTUALLY ASSIGNED to a collaborator linked to them, plus
        // unassigned orphans for triage. The "see all to delegate"
        // view stays in the /tenders dashboard via tenders.view-all.
        // This test proves a colleague's tender does NOT leak into
        // the manager's email — exactly the bug Mónica reported.
        $mgr = User::factory()->create([
            'email'     => 'mgr@hp-group.org',
            'role'      => 'manager',
            'is_active' => true,
        ]);

        // The manager has her own collaborator row, no restriction.
        $c = new TenderCollaborator();
        $c->name            = 'Manager';
        $c->normalized_name = TenderCollaborator::normalize('Manager');
        $c->email           = $mgr->email;
        $c->is_active       = true;
        $c->save();

        // Three tenders assigned to a DIFFERENT person — these used to
        // appear in the manager's digest under the old "supervisor"
        // policy. After the fix they must NOT.
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

        // No bucket → no email. Manager has 0 assigned + 0 orphans → suppressed.
        $this->assertNull(
            $forMgr,
            'Manager must NOT receive other people\'s tenders in their digest'
        );
    }

    public function test_manager_digest_includes_unassigned_orphans_for_triage(): void
    {
        $mgr = User::factory()->create([
            'email'     => 'mgr@hp-group.org',
            'role'      => 'manager',
            'is_active' => true,
        ]);

        // Tender with NO assignee — supervisor needs to know about it.
        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'REF-ORPHAN-1',
            'title'                    => 'Sem responsável',
            'status'                   => Tender::STATUS_PENDING,
            'deadline_at'              => now()->addDays(7),
            'assigned_collaborator_id' => null,
        ]);

        // Tender assigned to someone else — must NOT appear.
        $other = new TenderCollaborator();
        $other->name = 'Outro';
        $other->normalized_name = 'outro';
        $other->is_active = true;
        $other->save();
        $this->tender('nspa', $other->id);

        $recipients = $this->svc->buildRecipients();
        $forMgr     = collect($recipients)->firstWhere('user.id', $mgr->id);

        $this->assertNotNull($forMgr, 'Manager must receive a digest when orphans need triage');
        $this->assertSame(1, $forMgr['total'], 'Only the orphan counts, not the colleague\'s assigned tender');
        $this->assertArrayHasKey('unassigned_for_review', $forMgr['groups']);
        $this->assertCount(1, $forMgr['groups']['unassigned_for_review']);
    }

    public function test_user_digest_does_not_include_orphan_section(): void
    {
        // Regular users are not supervisors — orphans aren't theirs to
        // triage. They should never see the unassigned section.
        $u = User::factory()->create([
            'email'     => 'usr@hp-group.org',
            'role'      => 'user',
            'is_active' => true,
        ]);

        $c = new TenderCollaborator();
        $c->name = 'Usr'; $c->normalized_name = 'usr';
        $c->email = $u->email; $c->is_active = true; $c->save();

        // One assigned tender to user, one orphan.
        $this->tender('nspa', $c->id);
        Tender::create([
            'source' => 'nspa', 'reference' => 'REF-ORPHAN-2', 'title' => 'orphan',
            'status' => Tender::STATUS_PENDING, 'deadline_at' => now()->addDays(7),
            'assigned_collaborator_id' => null,
        ]);

        $recipients = $this->svc->buildRecipients();
        $forUser    = collect($recipients)->firstWhere('user.id', $u->id);

        $this->assertNotNull($forUser);
        $this->assertSame(1, $forUser['total'], 'User must see only their own tender, not the orphan');
        $this->assertArrayNotHasKey('unassigned_for_review', $forUser['groups']);
    }
}
