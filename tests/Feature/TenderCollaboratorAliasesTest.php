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

    public function test_no_alias_match_creates_new_row(): void
    {
        $this->row('Mónica Pereira');   // no aliases

        $created = TenderCollaborator::findOrCreateByName('Mónica');
        $this->assertSame('Mónica', $created->name);
        $this->assertSame('monica', $created->normalized_name);
        $this->assertSame(2, TenderCollaborator::count());
    }

    public function test_creating_new_row_logs_warning_when_fuzzy_candidates_exist(): void
    {
        // "Monica Pereira" exists. Importer brings in just "Monica P." —
        // close enough to be the same person, but not an exact match.
        // Behaviour: row is still created (we don't block imports), but
        // a warning is logged so the operator can audit + run the merge.
        $this->row('Monica Pereira');

        Log::spy();

        TenderCollaborator::findOrCreateByName('Monica P.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'TenderCollaborator: creating new row despite fuzzy candidates exist',
                \Mockery::on(function ($ctx) {
                    return is_array($ctx)
                        && ($ctx['normalized'] ?? null) === 'monica p.'
                        && !empty($ctx['candidates']);
                })
            );
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
