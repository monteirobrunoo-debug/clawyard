<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Computes the per-recipient bucket of tenders for the daily digest email.
 *
 * Policy (revised 2026-04-27 after manager feedback):
 *
 *   Every active user — manager OR user — receives a digest scoped to
 *   the tenders ACTUALLY ASSIGNED to a collaborator linked to them.
 *   The "see everything to delegate" view lives on the /tenders
 *   dashboard for managers (`tenders.view-all` gate); we deliberately
 *   do NOT mirror that view in email any more, because:
 *
 *     1. Managers were getting daily emails treating them as the
 *        person responsible for tenders that belong to other people.
 *     2. They couldn't tell their own from the team's at a glance.
 *     3. The supervisor view is already one click away in the UI;
 *        push notifications for it just add noise.
 *
 *   Managers + admins additionally receive an "unassigned" section
 *   listing tenders with no collaborator yet — those are their job to
 *   triage, and they wouldn't otherwise show up in either the manager's
 *   forUser bucket or any user's bucket.
 *
 * A tender is "actionable" if its status is active (pending /
 * em_tratamento / submetido / avaliacao). Expired (>15d past deadline)
 * are excluded from every bucket.
 *
 * Empty buckets are suppressed upstream — we don't send empty emails.
 */
class TenderDailyDigestService
{
    /**
     * @return array{
     *     user: User,
     *     role: 'manager'|'user',
     *     groups: array<string, Collection<int, Tender>>,
     *     total: int
     * }[] One entry per recipient, groups keyed by urgency bucket.
     */
    public function buildRecipients(): array
    {
        $out = [];

        // Single pass: every active user with an email gets a digest
        // scoped to their own work. No special-casing on role for the
        // bulk of tenders — the only carve-out is the manager+ orphan
        // section appended below.
        $users = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'manager', 'user'])    // skip 'guest'
            ->whereNotNull('email')
            ->get();

        foreach ($users as $u) {
            $bucket = $this->actionableForUser($u->id);

            // Manager / admin extra: tenders without an assignee. They
            // don't belong to anyone yet, so they wouldn't surface via
            // forUser; the supervisor needs to know about them so the
            // triage queue doesn't fester.
            $orphans = collect();
            if (in_array($u->role, ['admin', 'manager'], true)) {
                $orphans = $this->actionableUnassigned();
            }

            if ($bucket->isEmpty() && $orphans->isEmpty()) continue;

            $groups = $this->groupByUrgency($bucket);
            if ($orphans->isNotEmpty()) {
                // Render orphans as a dedicated section after the
                // urgency bands. The email template iterates the
                // groups array in insertion order.
                $groups['unassigned_for_review'] = $orphans;
            }

            $out[] = [
                'user'   => $u,
                'role'   => in_array($u->role, ['admin', 'manager'], true) ? 'manager' : 'user',
                'groups' => $groups,
                'total'  => $bucket->count() + $orphans->count(),
            ];
        }

        return $out;
    }

    /**
     * Active tenders that have NO assignee yet. Manager+ digests
     * include this section so orphans get triaged promptly.
     */
    private function actionableUnassigned(): Collection
    {
        $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);
        return Tender::query()
            ->active()
            ->whereNull('assigned_collaborator_id')
            ->where(function ($q) use ($expiredCut) {
                $q->whereNull('deadline_at')
                  ->orWhere('deadline_at', '>=', $expiredCut);
            })
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->get();
    }

    /** Only tenders assigned to a collaborator linked to this user. */
    private function actionableForUser(int $userId): Collection
    {
        // Same expired-cutoff as the manager view so nobody is nagged about
        // deadlines that are already months old.
        $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);

        return Tender::query()
            ->active()
            ->forUser($userId)
            ->with('collaborator')
            ->where(function ($q) use ($expiredCut) {
                $q->whereNull('deadline_at')
                  ->orWhere('deadline_at', '>=', $expiredCut);
            })
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->get();
    }

    /**
     * Bucket by urgency so the email can render one section per band.
     *
     * @return array<string, Collection<int, Tender>>
     */
    private function groupByUrgency(Collection $tenders): array
    {
        $order = ['overdue', 'critical', 'urgent', 'soon', 'normal', 'unknown'];

        $byBucket = $tenders->groupBy(fn(Tender $t) => $t->urgency_bucket);

        // Preserve the severity order for rendering.
        $sorted = [];
        foreach ($order as $k) {
            if (isset($byBucket[$k]) && $byBucket[$k]->isNotEmpty()) {
                $sorted[$k] = $byBucket[$k];
            }
        }
        return $sorted;
    }
}
