<?php

namespace App\Support;

/**
 * Cheap, deterministic sensitivity scorer for LLM prompts.
 *
 * Problem statement
 * -----------------
 * The quality-vs-confidentiality tradeoff is false when it's applied to
 * *every* prompt. Most traffic is low-sensitivity (product questions,
 * logistics queries, email drafts for public-domain customers) and
 * Claude is the right call. A small fraction is high-sensitivity
 * (patent drafts, defence tender details, SAP rows about regulated
 * accounts) and even a well-redacted prompt shouldn't leave the
 * company network.
 *
 * This classifier gives every payload a `tier` so the caller can pick:
 *   - low    → external frontier model (Claude / GPT / Gemini)
 *   - medium → external model with forced 2-pass redaction
 *   - high   → local model only, never leaves the VPC
 *
 * Design
 * ------
 * Two signals combined:
 *
 *   1. PII density — run PiiRedactor and count the number of matches
 *      per 100 words. High density = likely a data dump.
 *
 *   2. Keyword triggers — a curated list of Portuguese + English terms
 *      that reliably indicate regulated content (patent, confidencial,
 *      classified, NATO, NCAGE, ITAR, ...). Matches weighted by rarity
 *      and bumped by explicit markers in the payload.
 *
 * Both signals are cheap (µs) and deterministic. No model call, no
 * network. The intent is that the classifier decision is itself
 * auditable — given the same input, always the same tier.
 *
 * Callers should NOT treat "low" as "free pass". Even low-sensitivity
 * prompts run through the PiiRedactor and the upstream audit log.
 * "low" means "go ahead with the external provider"; it doesn't mean
 * "skip controls".
 */
final class SensitivityClassifier
{
    public const TIER_LOW    = 'low';
    public const TIER_MEDIUM = 'medium';
    public const TIER_HIGH   = 'high';

    /**
     * Curated terms that reliably indicate regulated content. Each
     * weight is roughly "how confident we are that a single occurrence
     * implies high sensitivity". 0.30 = strong signal, 0.15 = moderate.
     *
     * Keep this list short and specific. The moment you add generic
     * terms ("contract", "customer"), recall drops and the tiering
     * becomes noise.
     */
    private const KEYWORD_WEIGHTS = [
        // Defence / export controlled
        'classified'       => 0.40,
        'nato restricted'  => 0.40,
        'nato secret'      => 0.40,
        'itar'             => 0.35,
        'ear controlled'   => 0.30,
        'export controlled'=> 0.25,
        'ncage'            => 0.20,
        // IP / legal
        'patent draft'     => 0.35,
        'patent application'=> 0.30,
        'provisional filing'=> 0.25,
        'trade secret'     => 0.25,
        'confidencial'     => 0.20,
        'confidential'     => 0.15,
        'under nda'        => 0.25,
        'sob nda'          => 0.25,
        // Finance
        'pre-ipo'          => 0.30,
        'material non-public'=> 0.30,
        // Health
        'dados clínicos'   => 0.30,
        'dados de saúde'   => 0.30,
        'clinical trial'   => 0.25,
    ];

    /**
     * Per-100-words PII density thresholds. Above `high`, force the
     * local tier even on benign-looking prompts — a dump of 50 emails
     * is a dump regardless of keywords.
     */
    private const DENSITY_MEDIUM_PER_100W = 1.0;
    private const DENSITY_HIGH_PER_100W   = 3.0;

    /** Aggregate keyword-weight thresholds. */
    private const KEYWORD_MEDIUM = 0.20;
    private const KEYWORD_HIGH   = 0.45;

    /**
     * Classify a single message payload (Anthropic /v1/messages shape
     * or a plain string).
     *
     * @return array{tier:string,score:float,signals:array<string,mixed>}
     */
    public static function classify(string|array $payload): array
    {
        $text = self::flatten($payload);
        $wordCount = max(1, str_word_count($text));

        // Signal 1 — PII density
        $piiCount = self::countPii($text);
        $density  = 100.0 * $piiCount / $wordCount;

        // Signal 2 — keyword weights
        $kwScore = 0.0;
        $hits    = [];
        $lower = mb_strtolower($text);
        foreach (self::KEYWORD_WEIGHTS as $term => $weight) {
            if (str_contains($lower, $term)) {
                $kwScore += $weight;
                $hits[] = $term;
            }
        }

        // Combined decision — the HIGHER of the two signals wins, so a
        // single strong trigger can't be washed out by low density.
        $tier = self::TIER_LOW;
        if ($density >= self::DENSITY_MEDIUM_PER_100W || $kwScore >= self::KEYWORD_MEDIUM) {
            $tier = self::TIER_MEDIUM;
        }
        if ($density >= self::DENSITY_HIGH_PER_100W || $kwScore >= self::KEYWORD_HIGH) {
            $tier = self::TIER_HIGH;
        }

        // Score is a rough 0..1 composite for dashboards/logs. Not used
        // for routing — the tier is canonical.
        $score = min(1.0, ($density / 6.0) + $kwScore);

        return [
            'tier'    => $tier,
            'score'   => round($score, 3),
            'signals' => [
                'words'            => $wordCount,
                'pii_hits'         => $piiCount,
                'pii_per_100_words'=> round($density, 2),
                'keyword_score'    => round($kwScore, 2),
                'keywords_matched' => $hits,
            ],
        ];
    }

    /** Normalise all plausible Anthropic payload shapes to a flat string. */
    private static function flatten(string|array $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }
        $buf = '';
        // Anthropic content blocks: {type:"text", text:"..."} | string
        foreach ($payload as $part) {
            if (is_string($part)) {
                $buf .= $part . "\n";
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $buf .= $part['text'] . "\n";
                continue;
            }
            // Full messages[] entry: {role, content: string|array}
            if (isset($part['content'])) {
                $buf .= self::flatten($part['content']) . "\n";
            }
        }
        return $buf;
    }

    /**
     * Count PII hits using PiiRedactor's existing rules. We do NOT
     * mutate the text — only ask the redactor how many matches it
     * found, so the classifier has zero side-effects.
     */
    private static function countPii(string $text): int
    {
        // Cheap pattern count mirroring PiiRedactor (kept decoupled so
        // a future PiiRedactor refactor does not silently change
        // sensitivity scoring).
        $patterns = [
            '/(?:\+|\b00)\s*[1-9]\d{0,2}[\s\-().]*(?:\d[\s\-().]*){7,14}/',
            '/\b(?:\d[ \-]?){12,18}\d\b/',
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
            '/(?<!\d)\d{9}(?!\d)/',
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            '/\b\d{8}\s*\d\s*[A-Z]{2}\d\b/',
            '/\b(?:password|secret|token|apikey|api_key|bearer)\s*[:=]\s*\S+/i',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        ];
        $count = 0;
        foreach ($patterns as $re) {
            if (preg_match_all($re, $text, $_) !== false) {
                $count += preg_match_all($re, $text, $_);
            }
        }
        return $count;
    }
}
