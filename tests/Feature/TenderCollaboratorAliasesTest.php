<?php

namespace Tests\Feature;

use App\Models\TenderCollaborator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Locks the alias-matching contract on TenderCollaborator. The bug
 * shape this prevents (2026-04-25): an Excel re-import wrote
 * "Mónica" instead of "Mónica Pereira" → exact-name lookup missed
 * the existing row → 73 tenders went to a brand-new orphan row.
 *
 * With aliases:
 *   • findByAlias finds rows whose `aliases` JSON contains the name.
 *   • findOrCreateByName tries exact match → alias match → creates
 *     a new row (with a warning log when fuzzy candidates exist).
 *   • findCloseMatches surfaces near-name rows so the merge tool
 *     can suggest "did you mean…".
 */
class TenderCollaboratorAliasesTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $name, ?array $aliases = null): TenderCollaborator
    {
        return TenderCollaborator::create([
            'name'            => $name,
            'normalized_name' => TenderCollaborator::normalize($name),
            'aliases'         => $aliases,
            'is_active'       => true,
        ]);
    }

    public function test_exact_normalized_name_still_wins(): void
    {
        $this->row('Mónica Pereira');

        $hit = TenderCollaborator::findOrCreateByName('Monica Pereira');   // accent-insensitive normalisation
        $this->assertSame('Mónica Pereira', $hit->name);
        $this->assertSame(1, TenderCollaborator::count(),
            'Exact match must reuse the row, not create a new one');
    }

    public function test_alias_match_resolves_to_existing_row(): void
    {
        // Row carries 'monica' as an alias on top of its main name.
        $this->row('Mónica Pereira', aliases: ['monica']);

        $hit = TenderCollaborator::findOrCreateByName('Mónica');
        $this->assertSame('Mónica Pereira', $hit->name);
        $this->assertSame(1, TenderCollaborator::count(),
            'Alias match must NOT create a new row');
    }

    public function test_alias_match_is_case_insensitive_and_accent_insensitive(): void
    {
        $this->row('Mónica Pereira', aliases: ['monica']);

        // Different casing + an accent — normalisation should bring them
        // to the same form before the JSON lookup runs.
        $hit = TenderCollaborator::findOrCreateByName('  MÓNICA  ');
        $this->assertSame('Mónica Pereira', $hit->name);
    }

    public function test_close_name_no_alias_now_auto_links_instead_of_creating(): void
    {
        // 2026-04-28: was "creates a 2nd row" — now auto-links because
        // 'monica' is a prefix of 'monica pereira' (fuzzy match).
        $existing = $this->row('Mónica Pereira');

        $resolved = TenderCollaborator::findOrCreateByName('Mónica');
        $this->assertSame($existing->id, $resolved->id,
            'Close-name lookup must auto-link, not duplicate');
        $this->assertSame(1, TenderCollaborator::count());
        $this->assertContains('monica', $resolved->fresh()->aliases ?? []);
    }

    public function test_fuzzy_match_auto_links_to_existing_instead_of_creating(): void
    {
        // Behaviour change 2026-04-28: "Zé Inácio = Jose Inacio" — a
        // fuzzy match must REUSE the existing row (and write the
        // variant into aliases for free) rather than create a parallel
        // duplicate.
        $existing = $this->row('Jose Inacio');

        Log::spy();
        $resolved = TenderCollaborator::findOrCreateByName('Zé Inácio');

        $this->assertSame($existing->id, $resolved->id,
            'Fuzzy match must auto-link to the existing row');
        $this->assertSame(1, TenderCollaborator::count(),
            'No new row created');
        $this->assertContains('ze inacio', $existing->fresh()->aliases ?? [],
            'The variant must be persisted as an alias for future imports');

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'TenderCollaborator: auto-linked import variant to existing row',
                \Mockery::on(function ($ctx) {
                    return is_array($ctx)
                        && ($ctx['normalized'] ?? null) === 'ze inacio'
                        && ($ctx['linked_to']['name'] ?? null) === 'Jose Inacio';
                })
            );
    }

    public function test_strict_mode_refuses_to_create_when_no_match(): void
    {
        // strict=true → if no exact, alias, or fuzzy match exists,
        // return null (the import will leave the tender unassigned).
        // Useful for the "manual triage queue" workflow.
        $resolved = TenderCollaborator::findOrCreateByName('Brand New Person', strict: true);
        $this->assertNull($resolved);
        $this->assertSame(0, TenderCollaborator::count());
    }

    public function test_strict_mode_still_uses_fuzzy_match_when_one_exists(): void
    {
        // strict=true should NOT block fuzzy matching — the whole
        // point of strict is "don't create new", not "don't link".
        $existing = $this->row('Jose Inacio');

        $resolved = TenderCollaborator::findOrCreateByName('Zé Inácio', strict: true);
        $this->assertSame($existing->id, $resolved->id);
    }

    public function test_no_fuzzy_candidate_creates_new_in_default_mode(): void
    {
        // When a name has NO fuzzy candidate at all, the default mode
        // creates the row — imports keep flowing for genuinely new
        // collaborators (e.g. a new H&P hire whose name appears for
        // the first time).
        $created = TenderCollaborator::findOrCreateByName('Brand New Person');
        $this->assertNotNull($created);
        $this->assertSame('Brand New Person', $created->name);
        $this->assertSame(1, TenderCollaborator::count());
    }

    public function test_creating_brand_new_name_does_not_log_warning(): void
    {
        $this->row('Monica Pereira');

        Log::spy();

        // "Carlos Albuquerque" has no fuzzy match — clean creation path.
        TenderCollaborator::findOrCreateByName('Carlos Albuquerque');

        Log::shouldNotHaveReceived('warning',
            [\Mockery::pattern('/fuzzy candidates/')]
        );
    }

    public function test_find_close_matches_returns_near_names_only(): void
    {
        $this->row('Monica Pereira');
        $this->row('Monica Pinto');
        $this->row('Carlos Albuquerque');

        $close = TenderCollaborator::findCloseMatches('monica');
        $names = $close->pluck('normalized_name')->all();

        // Both Monica rows should appear (Levenshtein < 3 and length
        // difference under threshold). Carlos shouldn't.
        $this->assertNotContains('carlos albuquerque', $names);
        $this->assertContains('monica pereira', $names);
    }

    public function test_find_close_matches_excludes_exact_match(): void
    {
        $this->row('Monica');

        // 'monica' against 'monica' has distance 0 — but we explicitly
        // skip exact matches because that path is handled before
        // fuzzy is consulted.
        $close = TenderCollaborator::findCloseMatches('monica');
        $this->assertCount(0, $close);
    }

    public function test_blank_or_dash_name_returns_null_no_row_created(): void
    {
        $this->assertNull(TenderCollaborator::findOrCreateByName(''));
        $this->assertNull(TenderCollaborator::findOrCreateByName('-'));
        $this->assertNull(TenderCollaborator::findOrCreateByName(null));
        $this->assertSame(0, TenderCollaborator::count());
    }
}
