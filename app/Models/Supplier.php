<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistent supplier directory — see migration for design rationale.
 *
 * Lookup pattern:
 *   $sup = Supplier::firstOrNew(['slug' => Supplier::makeSlug($name)]);
 *   $sup->mergeFrom([...]);
 *   $sup->save();
 *
 * The merge helpers (mergeEmail, mergeCategories, mergeBrands) UNION
 * with existing values so each agent extraction enriches the row
 * rather than clobbering what the Excel seed (or another agent)
 * already populated.
 */
class Supplier extends Model
{
    protected $fillable = [
        'name', 'slug', 'legal_name', 'country_code', 'website',
        'primary_email', 'additional_emails', 'phones',
        'iqf_score', 'status',
        'categories', 'subcategories', 'brands',
        'source', 'source_meta',
        'total_outreach', 'total_replies', 'avg_reply_hours',
        'last_contacted_at', 'last_replied_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'additional_emails'  => 'array',
            'phones'             => 'array',
            'categories'         => 'array',
            'subcategories'      => 'array',
            'brands'             => 'array',
            'source_meta'        => 'array',
            'iqf_score'          => 'decimal:2',
            'last_contacted_at'  => 'datetime',
            'last_replied_at'    => 'datetime',
        ];
    }

    public const STATUS_APPROVED   = 'approved';
    public const STATUS_PENDING    = 'pending';      // auto-extracted, awaiting human review
    public const STATUS_BLACKLIST  = 'blacklisted';

    public const SOURCE_EXCEL_2026     = 'excel_2026';
    public const SOURCE_AGENT          = 'agent_extraction';
    public const SOURCE_MANUAL         = 'manual';

    // ── Scopes ──────────────────────────────────────────────────────────

    /** Suppliers that are OK to surface in the tender suggester. */
    public function scopeContactable(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING]);
    }

    /** Match by category code (top-level, e.g. "13" for Military). */
    public function scopeInCategory(Builder $q, string $code): Builder
    {
        // Use the JSONB containment operator — fast on Postgres with a
        // GIN index (we don't add one yet; add when the table grows).
        // Fallback to LIKE on the JSON text for SQLite test envs.
        $driver = $q->getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            return $q->whereRaw('categories @> ?', [json_encode([$code])]);
        }
        return $q->where('categories', 'LIKE', '%"' . $code . '"%');
    }

    /** Free-text search across name, legal_name and primary_email. */
    public function scopeSearch(Builder $q, string $needle): Builder
    {
        $like = '%' . mb_strtolower(trim($needle)) . '%';
        return $q->where(function ($w) use ($like) {
            $w->whereRaw('LOWER(name) LIKE ?', [$like])
              ->orWhereRaw('LOWER(legal_name) LIKE ?', [$like])
              ->orWhereRaw('LOWER(primary_email) LIKE ?', [$like])
              ->orWhereRaw('LOWER(slug) LIKE ?', [$like]);
        });
    }

    // ── Slug helper ─────────────────────────────────────────────────────

    /**
     * Convert a free-text supplier name into a stable slug used for
     * dedup. Strips accents, lowercases, drops legal suffixes, collapses
     * non-alphanumerics. Two writers naming the same supplier slightly
     * differently still collide on the same slug.
     *
     * Examples:
     *   "Wärtsilä Iberia, S.A."       → "wartsila-iberia"
     *   "WARTSILA IBERIA SA"          → "wartsila-iberia"
     *   "MAN Energy Solutions Lda."   → "man-energy-solutions"
     *   "AAR Supply Chain, Inc - DBA" → "aar-supply-chain"
     */
    public static function makeSlug(string $name): string
    {
        $s = trim($name);
        if ($s === '') return '';

        // Transliterate accents → ASCII. The Intl Transliterator gives
        // clean output across platforms ("HIDRÁULICO" → "HIDRAULICO",
        // "WÄRTSILÄ" → "WARTSILA"). iconv//TRANSLIT on macOS leaves
        // apostrophe artifacts ("Á" → "'A"), which split the slug
        // into "hidr-aulico" — avoided by preferring Intl when present.
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::createFromRules(
                ':: Any-Latin; :: Latin-ASCII; :: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;',
                \Transliterator::FORWARD
            );
            if ($tr) {
                $t = $tr->transliterate($s);
                if (is_string($t) && $t !== '') $s = $t;
            }
        } elseif (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') {
                // Strip apostrophe artifacts that some libiconv builds
                // emit (e.g. "Á" → "'A").
                $s = preg_replace("/[`'^~\"]/", '', $t) ?? $t;
            }
        }

        $s = mb_strtolower($s, 'UTF-8');

        // Strip common legal suffixes — they're not identifying.
        // Order matters: longer/more-specific first.
        $suffixes = [
            ', dba', ' dba', '- dba ', ' - dba',
            ', inc.', ' inc.', ', inc', ' inc',
            ', llc', ' llc', ', l.l.c.', ' l.l.c.',
            ', ltd.', ' ltd.', ', ltd', ' ltd',
            ', s.a.', ' s.a.', ', sa', ' sa ', ' sa.',
            ', s.l.', ' s.l.', ', sl', ' sl ',
            ', lda.', ' lda.', ', lda', ' lda',
            ' unipessoal lda', ' unipessoal',
            ' s.r.l.', ' srl',
            ' gmbh', ' a.g.', ' ag',
            ' bv', ' b.v.',
            ' co.', ' co ',
        ];
        foreach ($suffixes as $sfx) {
            $s = str_replace($sfx, ' ', $s);
        }

        // Collapse non-alphanumerics → single dash. Trim leading/
        // trailing dashes. Cap length so a freak ~200-char company
        // name doesn't blow our 255-char column.
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        $s = trim($s, '-');
        if (mb_strlen($s) > 200) $s = mb_substr($s, 0, 200);
        return $s;
    }

    // ── Merge helpers — used by importers + auto-extractor ──────────────

    /**
     * Merge a candidate email into primary_email + additional_emails.
     * If the supplier has no primary yet, this becomes primary; else
     * it's appended to additional_emails (deduped, lowercased).
     */
    public function mergeEmail(?string $email): self
    {
        if (!$email) return $this;
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this;

        if (empty($this->primary_email)) {
            $this->primary_email = $email;
            return $this;
        }
        if ($this->primary_email === $email) return $this;

        $bag = (array) ($this->additional_emails ?? []);
        $bag = array_values(array_unique(array_filter(array_map(
            fn($v) => is_string($v) ? mb_strtolower(trim($v)) : null,
            $bag
        ))));
        if (!in_array($email, $bag, true)) {
            $bag[] = $email;
            $this->additional_emails = $bag;
        }
        return $this;
    }

    /**
     * Union an array of category codes into the categories column.
     * Codes are kept as strings (the Matriz sheet uses "13", "16.34"
     * — we store both top-level and finer-grained on subcategories).
     */
    public function mergeCategories(array $codes, bool $sub = false): self
    {
        $col = $sub ? 'subcategories' : 'categories';
        $current = (array) ($this->$col ?? []);
        $merged = array_values(array_unique(array_map(
            fn($c) => trim((string) $c),
            array_merge($current, $codes)
        )));
        $merged = array_values(array_filter($merged, fn($c) => $c !== ''));
        sort($merged);
        $this->$col = $merged ?: null;
        return $this;
    }

    public function mergeBrands(array $brands): self
    {
        $current = (array) ($this->brands ?? []);
        $merged = array_values(array_unique(array_map(
            fn($b) => trim((string) $b),
            array_merge($current, $brands)
        )));
        $merged = array_values(array_filter($merged, fn($b) => $b !== ''));
        sort($merged);
        $this->brands = $merged ?: null;
        return $this;
    }
}
