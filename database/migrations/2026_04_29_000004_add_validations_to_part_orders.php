<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peer review for autonomous purchases. After a buyer picks a part,
 * 2 OTHER agents (not the buyer, not the helpers from committee) do
 * a sanity review:
 *
 *   • Does this part actually fit the slot?
 *   • Is there a clearly better/cheaper alternative on the market?
 *   • Should the cost have been lower for this kind of part?
 *
 * Each reviewer emits {verdict: 'approve' | 'concern', note: '<1 sentence>'}.
 * The combined list is persisted as JSON on the part_orders row.
 *
 * No status change happens automatically based on validation — the
 * order proceeds to CAD as before. The validations are visible in
 * the /marketplace + /robot UI as a 'validated by N agents' badge,
 * with hover tooltip showing the dissenting opinions if any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_orders', function (Blueprint $t) {
            if (!Schema::hasColumn('part_orders', 'validations')) {
                $t->json('validations')->nullable()->after('committee_log');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_orders', function (Blueprint $t) {
            $t->dropColumn('validations');
        });
    }
};
