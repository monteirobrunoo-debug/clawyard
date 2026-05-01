<?php

namespace App\Services;

use App\Models\Tender;

/**
 * Curriculum-routing helper for tenders.
 *
 * The Hernandez et al. (2026) review finds that progressive learning
 * (curriculum) is one of three core paradigms in autonomous-agent
 * systems: light pipelines for easy tasks, heavier ensembles for
 * harder ones. Translating to clawyard:
 *
 *   • Easy   → known recurrent source, familiar category, deadline
 *              comfortably in the future. Run only the local
 *              suggester (no Tavily, no swarm). Saves 60-70% of the
 *              token budget on routine NSPA/Acingov rows.
 *
 *   • Medium → mainstream tender. Run the standard suggester pipe
 *              (Tavily web search + local match + cron pre-warm).
 *              This is the default behaviour today.
 *
 *   • Hard   → military / multi-category / short-deadline / unknown
 *              source. Run the full pipe AND escalate (extra
 *              reviewer agent, larger supplier shortlist, raise
 *              priority for the assignee).
 *
 * Confidential tenders skip this classifier entirely — they go
 * through the preventive lane regardless of difficulty.
 */
class TenderDifficultyClassifier
{
    public const EASY   = 'easy';
    public const MEDIUM = 'medium';
    public const HARD   = 'hard';

    /**
     * Returns the difficulty bucket plus a list of reason-codes that
     * drove the classification (useful for the dashboard pill tooltip).
     *
     * @return array{level:string, reasons:array<string>}
     */
    public function classify(Tender $tender): array
    {
        $reasons = [];
        $score = 0;     // higher = harder

        // 1) Source familiarity — well-known recurrent sources are
        // easier; novel ones are harder.
        $source = mb_strtolower((string) $tender->source);
        $easySources = ['nspa', 'acingov', 'sam_gov', 'vortal'];
        $hardSources = ['ungm', 'unido', 'ncia'];   // less standardised
        if (in_array($source, $easySources, true)) {
            $score -= 1;
        } elseif (in_array($source, $hardSources, true)) {
            $score += 1;
            $reasons[] = "fonte heterogénea ({$source})";
        } elseif ($source === '') {
            $score += 1;
            $reasons[] = 'sem fonte conhecida';
        }

        // 2) Deadline urgency — short fuse demands escalation.
        $days = $tender->days_to_deadline ?? null;
        if ($days !== null) {
            if ($days <= 3) {
                $score += 2;
                $reasons[] = "deadline ≤ 3d";
            } elseif ($days <= 7) {
                $score += 1;
                $reasons[] = "deadline ≤ 7d";
            } elseif ($days >= 14) {
                $score -= 1;
            }
        }

        // 3) Domain — Military (cat 13) tilts harder; pure Industrial
        // (cat 15) is the bread-and-butter case.
        $title = mb_strtolower((string) $tender->title . ' ' . (string) $tender->purchasing_org);
        $militaryHits = ['military', 'militar', 'defense', 'defesa', 'naval defence',
                         'arma', 'munição', 'missile', 'torpedo', 'nato classified',
                         'tactical', 'aerospace', 'jet engine'];
        foreach ($militaryHits as $h) {
            if (str_contains($title, $h)) {
                $score += 2;
                $reasons[] = "tópico militar/defesa";
                break;
            }
        }

        // 4) Value heuristic — high-value tenders deserve more agent
        // depth; low-value can go through the easy lane.
        $value = (float) ($tender->offer_value ?? 0);
        if ($value > 100_000) {
            $score += 1;
            $reasons[] = 'valor > 100k';
        } elseif ($value > 0 && $value < 5_000) {
            $score -= 1;
        }

        // 5) Multi-category title (engine + electrical + cable + …)
        // is a sign of complex RFQ that benefits from broader research.
        $multiHits = 0;
        foreach (['engine', 'pump', 'cable', 'valve', 'motor', 'generator',
                  'compressor', 'electric', 'sensor', 'radar', 'sonar'] as $kw) {
            if (str_contains($title, $kw)) $multiHits++;
        }
        if ($multiHits >= 3) {
            $score += 1;
            $reasons[] = "{$multiHits} domínios técnicos no título";
        }

        // Bucket — symmetric cutoffs.
        $level = match (true) {
            $score >= 3  => self::HARD,
            $score <= -1 => self::EASY,
            default      => self::MEDIUM,
        };

        // De-dup reason codes (some keywords double-fire).
        $reasons = array_values(array_unique($reasons));

        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * Convenience: how many local suppliers should the suggester
     * surface for this tender? Curriculum decides.
     */
    public function localSupplierLimit(string $level): int
    {
        return match ($level) {
            self::EASY => 4,    // operator wants 1-2 quick choices
            self::HARD => 12,   // wider net for due diligence
            default    => 8,    // medium = default
        };
    }

    /** Should this difficulty include Tavily web augmentation? */
    public function shouldIncludeWeb(string $level): bool
    {
        // Easy tenders are recurrent — local directory is enough.
        // Skipping Tavily here saves the ~$0.001/call quota for
        // tenders that don't benefit from it.
        return $level !== self::EASY;
    }
}
