<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.extra_permissions JSON column for granular ad-hoc grants
 * without promoting a user to manager/admin.
 *
 * 2026-05-19 — pedido directo do operador:
 *   "dá acesso ao user eduardo.rio@hp-group.org de importar tabelas
 *    da NSPA ou acingov/Vortal"
 *
 * Hoje a gate tenders.import só passa para isManager() (role in
 * ['admin','manager']). Promover Eduardo a manager seria overkill —
 * dá-lhe também tenders.view-all, tenders.assign, tenders.collaborators
 * e o Robot/Council/Intel nav.
 *
 * Esta coluna permite grants finos. Exemplo:
 *   ['tenders.import', 'tenders.assign']
 *
 * Convenção: null = sem permissões extra (default), array = lista de
 * keys de gates que o user pode usar para além das do seu role.
 * Atom: gate name exactly como definido em AppServiceProvider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('extra_permissions')->nullable()->after('allowed_nav');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('extra_permissions');
        });
    }
};
