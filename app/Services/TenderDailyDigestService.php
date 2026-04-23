<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Computes the per-recipient bucket of tenders for the daily digest email.
 *
 * User-level requirement ("ambos, o super user recebe e o user"):
 *   • Manager+ users receive a digest covering EVERY active tender that
 *     needs attention — this is the "super-user oversees everyone" view.
 *   • Regular users receive only tenders whose assigned collaborator is
 *     linked to their User account — this matches what they see in the
 *     dashboard's "mine" strip.
 *
 * A tender is "actionable" if any of the following is true:
 *   • status = pending / em_tratamento / submetido / avaliacao (active)
 *   • AND it either has a deadline in the next 14 days, is already
 *     overdue, or still lacks a SAP opportunity number.
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

        // 1) Super-users (manager+): one digest per manager covering
        //    all actionable active tenders.
        $managers = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'manager'])
            ->whereNotNull('email')
            ->get();

        if ($managers->isNotEmpty()) {
            $managerBucket = $this->actionableForManager();
            if ($managerBucket->isNotEmpty()) {
                $grouped = $this->groupByUrgency($managerBucket);
                foreach ($managers as $m) {
                    $out[] = [
                        'user'   => $m,
                        'role'   => 'manager',
                        'groups' => $grouped,
                        'total'  => $managerBucket->count(),
                    ];
                }
            }
        }

        // 2) Regular users (active, non-manager): one digest per user
        //    scoped to the collaborators linked to that User.
        $users = User::query()
            ->where('is_active', true)
            ->whereNotIn('role', ['admin', 'manager'])
            ->whereNotNull('email')
            ->get();

        foreach ($users as $u) {
            $bucket = $this->actionableForUser($u->id);
            if ($bucket->isEmpty()) continue;

            $out[] = [
                'user'   => $u,
                'role'   => 'user',
                'groups' => $this->groupByUrgency($bucket),
                'total'  => $bucket->count(),
            ];
        }

        return $out;
    }

    /** All active tenders that still need SOMEONE to act. */
    private function actionableForManager(): Collection
    {
        // Exclude "expired" (overdue by > OVERDUE_WINDOW_DAYS) — they're
        // abandoned, not actionable, and would just add noise to the email.
        $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);

        return Tender::query()
            ->active()
            ->with('collaborator')
            ->where(function ($q) {
                $q->whereNull('deadline_at')
                  ->orWhere('deadline_at', '<=', now()->addDays(14))
                  ->orWhereNull('sap_opportunity_number')
                  ->orWhere('sap_opportunity_number', '');
            })
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
