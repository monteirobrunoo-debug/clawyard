<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos descobertos no SEGA SEGK (catálogo NATO turco, 34M rows):
 *   • replaced_by      ← NsnReplacement1  (NSN sucessor)
 *   • replaced_by_2    ← NsnReplacement2  (segundo sucessor, se houver)
 *   • niin_status_code ← NiinStatusCode   ('C' canceled, 'X' replaced, etc.)
 *
 * Permite ao Cor. Rodrigues / Marco Sales avisar quando um NSN está obsoleto
 * e qual o substituto oficial.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('nato_nsn', function (Blueprint $t) {
            $t->string('replaced_by', 20)->nullable()->after('manufacturer_pn');
            $t->string('replaced_by_2', 20)->nullable()->after('replaced_by');
            $t->string('niin_status_code', 5)->nullable()->after('replaced_by_2');
            $t->index('niin_status_code');
        });
    }

    public function down(): void
    {
        Schema::table('nato_nsn', function (Blueprint $t) {
            $t->dropIndex(['niin_status_code']);
            $t->dropColumn(['replaced_by', 'replaced_by_2', 'niin_status_code']);
        });
    }
};
