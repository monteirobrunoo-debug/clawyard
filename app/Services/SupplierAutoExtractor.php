<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;

/**
 * Pulls supplier candidates out of free-text agent responses.
 *
 * Trigger: any assistant message containing ≥1 email address. The
 * Message model's `created` hook calls extractFrom().
 *
 * What we try to do:
 *   • For each email address found, infer a "supplier name" — the
 *     nearest preceding capitalised noun (or domain → name fallback).
 *   • Upsert into suppliers (slug match) — if the row already exists
 *     (Excel seed, or earlier extraction), we ENRICH:
 *       - merge email into primary_email/additional_emails
 *       - merge any brand mentions found nearby
 *   • If the row is brand new, create with status = pending so a
 *     manager can promote it via /suppliers (we don't want raw
 *     LLM-extracted candidates auto-approved into the dashboard).
 *
 * Heuristics > perfect:
 *   • We over-collect rather than miss. Pending rows can be cleaned
 *     up in /suppliers. Missing a real supplier is worse than
 *     creating a bogus row.
 *   • Skip obviously personal/internal emails (same domain as ours,
 *     gmail/hotmail/yahoo, no-reply/donotreply, info@ if there's no
 *     better candidate).
 */
class SupplierAutoExtractor
{
    /** Domains that are NEVER suppliers — internal team or generic. */
    private const SKIP_DOMAINS = [
        'partyard.eu', 'hp-group.org', 'hp-group.com',
        'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com',
        'yahoo.com', 'yahoo.fr', 'live.com', 'icloud.com', 'me.com',
        'protonmail.com', 'proton.me',
    ];

    /** Local-parts that are usually generic and not worth attributing. */
    private const NOISY_LOCAL = [
        'no-reply', 'noreply', 'donotreply', 'do-not-reply',
        'mailer-daemon', 'postmaster', 'webmaster',
    ];

    /**
     * Returns the number of suppliers created (excluding updates).
     */
    public function extractFrom(string $content, array $context = []): int
    {
        // Strip code blocks first — emails inside ``` are usually part
        // of an example or a SAP query string, not real candidates.
        $stripped = preg_replace('/```[\s\S]*?```/', '', $content) ?? $content;

        $emails = $this->findEmails($stripped);
        if (empty($emails)) return 0;

        $brandHints = $this->findBrandMentions($stripped);

        $created = 0;
        foreach ($emails as $found) {
            $email = mb_strtolower($found['email']);
            $domain = $this->domainOf($email);
            if ($this->shouldSkip($email, $domain)) continue;

            $name = $found['name_hint'] ?: $this->nameFromDomain($domain);
            if ($name === '') continue;

            $slug = Supplier::makeSlug($name);
            if ($slug === '') continue;

            try {
                $sup = Supplier::firstOrNew(['slug' => $slug]);
                $isNew = !$sup->exists;

                if ($isNew) {
                    $sup->name = $name;
                    $sup->status = Supplier::STATUS_PENDING;       // needs human review
                    $sup->source = Supplier::SOURCE_AGENT;
                    $sup->source_meta = $context + [
                        'extracted_at' => now()->toIso8601String(),
                        'first_email'  => $email,
                    ];
                }

                $sup->mergeEmail($email);
                if (!empty($brandHints)) $sup->mergeBrands($brandHints);

                $sup->save();
                if ($isNew) $created++;
            } catch (\Throwable $e) {
                Log::warning('SupplierAutoExtractor: upsert failed', [
                    'name'  => $name,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($created > 0) {
            Log::info('SupplierAutoExtractor created candidates', [
                'count'    => $created,
                'context'  => $context,
            ]);
        }

        return $created;
    }

    /**
     * Find email addresses with a best-effort "supplier name hint" —
     * the capitalised phrase appearing immediately before the email
     * (within 80 chars), if any. Returns:
     *   [['email' => 'sales@wartsila.com', 'name_hint' => 'Wartsila Iberia'], ...]
     */
    private function findEmails(string $text): array
    {
        $emailRe = '/([a-zA-Z0-9._+\-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+)/';
        if (!preg_match_all($emailRe, $text, $matches, PREG_OFFSET_CAPTURE)) return [];

        $out = [];
        foreach ($matches[1] as $m) {
            [$email, $offset] = $m;

            // Look back up to 120 chars for a name-like phrase ending
            // before the email. Common patterns we want to catch:
            //   "Wärtsilä Iberia (sales@wartsila.com)"
            //   "**Wartsila** — sales@wartsila.com"
            //   "1. Wartsila — Email: sales@wartsila.com"
            //   "Marca: MTU\nEmail: info@mtu.eu"
            //   "Contact: João Silva <joao@empresa.pt>"
            $start = max(0, $offset - 120);
            $window = substr($text, $start, $offset - $start);

            // Trim common separators between the name and the email.
            $window = rtrim($window, " \t<:—–-—\n\r,;|()[]");

            // Drop trailing labels like "Email", "E-mail", "Mail",
            // "Contacto", "Contact", "—".
            $window = preg_replace('/\b(?:e\-?mail|email|mail|contacto|contact|para|to)\b\s*[:\-—]?\s*$/iu', '', $window) ?? $window;
            $window = rtrim($window, " \t<:—–-—\n\r,;|()[]");

            // Now grab the LAST line of the window (multi-line buffers
            // would otherwise mash the prior bullet point into our hint).
            $lines = preg_split('/[\r\n]+/', $window) ?: [$window];
            $lastLine = trim(end($lines));

            // From that line, find the longest run of TitleCase words
            // ending at the right edge. "1. **Wärtsilä Iberia, S.A.**"
            // → "Wärtsilä Iberia". Stops at sentence punctuation.
            $hint = '';
            if (preg_match('/(?:[\p{L}][\p{L}0-9&.\-]*\s+){0,5}[\p{L}][\p{L}0-9&.\-]*$/u', $lastLine, $hm)) {
                $hint = trim($hm[0]);
                // Drop common bullet prefixes ("1.", "•", "-", "*").
                $hint = preg_replace('/^[\-•*\d.]+\s*/u', '', $hint) ?? $hint;
                // Drop wrapping markdown emphasis.
                $hint = trim(preg_replace('/^[*_]+|[*_]+$/u', '', $hint) ?? $hint);
                // Drop trailing legal qualifiers — slug helper handles
                // them but we keep the display name clean.
                $hint = preg_replace('/[,\s]+$/u', '', $hint) ?? $hint;
                // Reject hints that are just a generic word ("Email",
                // "Para", "Subject"). Need ≥2 chars and at least one
                // upper-case letter to look like a brand/company name.
                if (mb_strlen($hint) < 2 || !preg_match('/\p{Lu}/u', $hint)) {
                    $hint = '';
                }
                // Reject hints that ARE the email (e.g. "sales@x.com"
                // floated up into the window as a duplicate).
                if (str_contains($hint, '@')) $hint = '';
            }

            $out[] = ['email' => $email, 'name_hint' => $hint];
        }
        return $out;
    }

    private function domainOf(string $email): string
    {
        $parts = explode('@', $email);
        return mb_strtolower(end($parts));
    }

    private function shouldSkip(string $email, string $domain): bool
    {
        if ($domain === '' || in_array($domain, self::SKIP_DOMAINS, true)) return true;
        $local = explode('@', $email)[0] ?? '';
        if (in_array(mb_strtolower($local), self::NOISY_LOCAL, true)) return true;
        return false;
    }

    /**
     * Fallback: derive a supplier name from the email domain when the
     * hint heuristic returned nothing. "sales@wartsila-iberia.com" →
     * "Wartsila Iberia".
     */
    private function nameFromDomain(string $domain): string
    {
        $base = explode('.', $domain)[0] ?? '';
        if ($base === '') return '';
        $base = str_replace(['-', '_'], ' ', $base);
        return ucwords($base);
    }

    /**
     * Look for known brand names mentioned in the same response so we
     * can stamp them on the supplier row. The brand list mirrors the
     * Daniel Email persona (the brands H&P represents).
     */
    private function findBrandMentions(string $text): array
    {
        $brands = [
            'MTU', 'Caterpillar', 'CAT', 'MAK', 'Jenbacher', 'Cummins',
            'Wartsila', 'Wärtsilä', 'MAN', 'SKF', 'Schottel',
            'Mercedes', 'Yamaha', 'Bosch', 'Perkins', 'Sherwood', 'EVAC',
        ];
        $found = [];
        foreach ($brands as $b) {
            if (preg_match('/\b' . preg_quote($b, '/') . '\b/iu', $text)) {
                // Normalise to canonical form (Wärtsilä → Wartsila for slug-friendliness).
                $found[] = $b === 'Wärtsilä' ? 'Wartsila' : $b;
            }
        }
        return array_values(array_unique($found));
    }
}
