<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per port-workshop / OEM service centre that PartYard tracks.
 *
 * Fed by the `marco:import-partners` artisan command from the curated
 * Strategic Port Workshop Mapping xlsx. Two agents read the table:
 *
 *   • Marco (sales)  scopes via `domain('spares')`
 *   • Vasco (vessel) scopes via `domain('repair')`
 *
 * Both agents have the same `tools` access (find/contact/recommend) —
 * the difference is the default domain filter, so each gets a
 * relevant subset without duplicating endpoints.
 */
class PartnerWorkshop extends Model
{
    protected $fillable = [
        'port',
        'country',
        'region',
        'company_name',
        'category',
        'services_text',
        'service_tokens',
        'coverage_chips',
        'domains',
        'address',
        'phone',
        'email',
        'website',
        'relevance',
        'priority',
        'notes',
        'source_file',
        'source_row',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'service_tokens' => 'array',
            'coverage_chips' => 'array',
            'domains'        => 'array',
            'is_active'      => 'boolean',
        ];
    }

    // ── Priority vocabulary (matches the sheet's status column) ───────────
    public const PRIORITY_HIGH      = 'high_priority';
    public const PRIORITY_ACTIVE    = 'active_prospect';
    public const PRIORITY_CANDIDATE = 'partner_candidate';
    public const PRIORITY_PROSPECT  = 'prospect';
    public const PRIORITY_INFO_ONLY = 'info_only';

    public const PRIORITY_ORDER = [
        self::PRIORITY_HIGH      => 0,
        self::PRIORITY_ACTIVE    => 1,
        self::PRIORITY_CANDIDATE => 2,
        self::PRIORITY_PROSPECT  => 3,
        self::PRIORITY_INFO_ONLY => 4,
    ];

    // ── Domain vocabulary ──────────────────────────────────────────────────
    public const DOMAIN_SPARES = 'spares';
    public const DOMAIN_REPAIR = 'repair';

    // ── Scopes ─────────────────────────────────────────────────────────────
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Filter to one domain (`spares` or `repair`). NULL on the row ⇒
     * row is included (treat unknown as "everyone can see it"). This
     * matches the spirit of TenderCollaborator allowed_sources NULL.
     */
    public function scopeDomain(Builder $q, string $domain): Builder
    {
        $domain = strtolower(trim($domain));
        return $q->where(function ($w) use ($domain) {
            $w->whereNull('domains')
              ->orWhereJsonContains('domains', $domain);
        });
    }

    public function scopeForPort(Builder $q, string $port): Builder
    {
        // Port names in the sheet sometimes carry a slash ("Bremen /
        // Bremerhaven") — match by case-insensitive substring so the
        // agent can pass "Bremen" or "Bremerhaven" or the joined form.
        $needle = strtolower(trim($port));
        return $q->whereRaw('LOWER(port) LIKE ?', ['%' . $needle . '%']);
    }

    public function scopePriority(Builder $q, string $priority): Builder
    {
        return $q->where('priority', $priority);
    }

    /** Coverage chip helper — `whereChip('prime_movers')` etc. */
    public function scopeWhereChip(Builder $q, string $chip): Builder
    {
        // SQLite (test) fallback: no JSON_CONTAINS.
        $driver = $q->getModel()->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return $q->where('coverage_chips', 'like', '%"' . $chip . '":true%')
                     ->orWhere('coverage_chips', 'like', '%"' . $chip . '": true%');
        }
        return $q->whereJsonContains('coverage_chips->' . $chip, true);
    }

    /**
     * One-liner for the agent prompt — compact card with the essentials.
     * Used by PartnerWorkshopService when injecting context into a
     * conversation, so the LLM doesn't have to parse JSON columns.
     */
    public function toAgentLine(): string
    {
        $chips = collect($this->coverage_chips ?? [])
            ->filter(fn($v) => $v === true)
            ->keys()
            ->all();

        $bits = [];
        $bits[] = sprintf('**%s**', $this->company_name);
        $bits[] = sprintf('%s (%s)', $this->port, $this->country ?: '?');
        if ($this->priority) $bits[] = "[{$this->priority}]";
        if ($this->category) $bits[] = $this->category;
        if (!empty($chips))  $bits[] = 'cobre: ' . implode(', ', $chips);
        if ($this->phone)    $bits[] = '☎ ' . $this->phone;
        if ($this->email)    $bits[] = '✉ ' . $this->email;

        return implode(' · ', $bits);
    }
}
