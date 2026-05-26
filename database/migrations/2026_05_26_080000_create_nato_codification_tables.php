<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NATO Codification dataset — populado a partir de Excel (NCAGE + NSN).
 *
 * Pedido directo Bruno 2026-05-26: substituir Tavily lookup lento por
 * pesquisa local de dados NATO oficiais. Volume DO criado:
 *   /mnt/volume_ams3_0_ncrml_nato/  (4GB, vazio até população)
 *
 * 3 tabelas:
 *   • nato_ncage           — CAGE/NCAGE codes (fabricantes)
 *   • nato_nsn             — NSN basic data (description + FSC + NIIN)
 *   • nato_country_codes   — NIIN country code (2 digits) → país
 *
 * Indexes GIN + B-tree para pesquisa <10ms em milhões de rows.
 */
return new class extends Migration {
    public function up(): void
    {
        // ───────────────────────────────────────────────────────────────────
        // NCAGE — fabricantes globais (CAGE US/NCAGE NATO)
        // ───────────────────────────────────────────────────────────────────
        Schema::create('nato_ncage', function (Blueprint $t) {
            $t->id();
            $t->string('cage_code', 10)->unique();         // ex: "F0011", "1A123"
            $t->string('company_name', 300);                // ex: "RHEINMETALL AG"
            $t->string('country_code', 5)->nullable();      // ex: "DEU", "FRA"
            $t->string('country_name', 100)->nullable();    // ex: "Germany"
            $t->string('city', 150)->nullable();
            $t->text('address')->nullable();
            $t->string('postcode', 20)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('website', 300)->nullable();
            $t->string('status', 20)->nullable();           // ex: "Active", "Replaced"
            $t->string('replaced_by', 10)->nullable();      // se houver merge
            $t->json('raw')->nullable();                    // colunas extra do Excel
            $t->timestamps();

            $t->index('company_name');
            $t->index('country_code');
            $t->index('status');
        });

        // Postgres GIN trigram index para fuzzy company_name search.
        // Crítico: agentes pesquisam "Rheinmetall" e variantes "RHEINMETALL AG",
        // "Rheinmetall Defence", "Rheinmetall MAN", etc.
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::statement('CREATE INDEX nato_ncage_name_trgm ON nato_ncage USING GIN (company_name gin_trgm_ops)');
            } catch (\Throwable $e) {
                \Log::info('pg_trgm setup skipped: ' . $e->getMessage());
            }
        }

        // ───────────────────────────────────────────────────────────────────
        // NSN — NATO Stock Numbers
        // ───────────────────────────────────────────────────────────────────
        Schema::create('nato_nsn', function (Blueprint $t) {
            $t->id();
            // Format canónico: XXXX-XX-XXX-XXXX
            $t->string('nsn', 17)->unique();                // "5331-01-234-5678"
            $t->string('fsc', 4);                            // Federal Supply Class
            $t->string('fsc_name', 200)->nullable();        // "O-Rings, Round, Square..."
            $t->string('ncb', 2);                            // NATO Country code (2 digits)
            $t->string('niin', 9);                           // 9-digit national item ID
            $t->text('description')->nullable();             // Item description
            $t->string('unit_of_issue', 10)->nullable();    // "EA", "PK", "BX"
            $t->string('manufacturer_cage', 10)->nullable(); // FK to nato_ncage
            $t->string('manufacturer_pn', 100)->nullable();  // Part Number
            $t->string('hazardous_material_code', 10)->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();

            $t->index('fsc');
            $t->index('niin');
            $t->index('manufacturer_cage');
            $t->index(['fsc', 'ncb']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE INDEX nato_nsn_desc_trgm ON nato_nsn USING GIN (description gin_trgm_ops)');
            } catch (\Throwable $e) {
                \Log::info('nato_nsn trgm index skipped: ' . $e->getMessage());
            }
        }

        // ───────────────────────────────────────────────────────────────────
        // Country codes — NIIN prefix (2 digits) → país
        // ───────────────────────────────────────────────────────────────────
        Schema::create('nato_country_codes', function (Blueprint $t) {
            $t->id();
            $t->string('ncb_code', 2)->unique();             // "01" = USA, "00" = USA, "17" = Netherlands
            $t->string('country_name', 100);
            $t->string('iso_alpha2', 2)->nullable();
            $t->string('iso_alpha3', 3)->nullable();
            $t->string('nato_member', 5)->nullable();        // "Y"/"N"
            $t->timestamps();
        });

        // Seed básico — country codes não mudam muito
        $countries = [
            ['00', 'United States', 'US', 'USA', 'Y'],
            ['01', 'United States', 'US', 'USA', 'Y'],
            ['11', 'NATO HQ', null, null, 'Y'],
            ['12', 'Germany', 'DE', 'DEU', 'Y'],
            ['13', 'Belgium', 'BE', 'BEL', 'Y'],
            ['14', 'France', 'FR', 'FRA', 'Y'],
            ['15', 'Italy', 'IT', 'ITA', 'Y'],
            ['17', 'Netherlands', 'NL', 'NLD', 'Y'],
            ['21', 'Canada', 'CA', 'CAN', 'Y'],
            ['22', 'Denmark', 'DK', 'DNK', 'Y'],
            ['23', 'Greece', 'GR', 'GRC', 'Y'],
            ['24', 'Iceland', 'IS', 'ISL', 'Y'],
            ['25', 'Norway', 'NO', 'NOR', 'Y'],
            ['26', 'Portugal', 'PT', 'PRT', 'Y'],
            ['27', 'Turkey', 'TR', 'TUR', 'Y'],
            ['28', 'Luxembourg', 'LU', 'LUX', 'Y'],
            ['33', 'Spain', 'ES', 'ESP', 'Y'],
            ['34', 'Japan', 'JP', 'JPN', 'N'],
            ['35', 'Israel', 'IL', 'ISR', 'N'],
            ['37', 'South Korea', 'KR', 'KOR', 'N'],
            ['38', 'Egypt', 'EG', 'EGY', 'N'],
            ['39', 'Poland', 'PL', 'POL', 'Y'],
            ['43', 'Czech Republic', 'CZ', 'CZE', 'Y'],
            ['44', 'Hungary', 'HU', 'HUN', 'Y'],
            ['45', 'Slovakia', 'SK', 'SVK', 'Y'],
            ['46', 'Slovenia', 'SI', 'SVN', 'Y'],
            ['47', 'Romania', 'RO', 'ROU', 'Y'],
            ['48', 'Bulgaria', 'BG', 'BGR', 'Y'],
            ['49', 'Estonia', 'EE', 'EST', 'Y'],
            ['50', 'Latvia', 'LV', 'LVA', 'Y'],
            ['51', 'Lithuania', 'LT', 'LTU', 'Y'],
            ['53', 'Australia', 'AU', 'AUS', 'N'],
            ['57', 'New Zealand', 'NZ', 'NZL', 'N'],
            ['58', 'Saudi Arabia', 'SA', 'SAU', 'N'],
            ['61', 'Brazil', 'BR', 'BRA', 'N'],
            ['64', 'Argentina', 'AR', 'ARG', 'N'],
            ['66', 'Singapore', 'SG', 'SGP', 'N'],
            ['68', 'Sweden', 'SE', 'SWE', 'Y'],
            ['70', 'Finland', 'FI', 'FIN', 'Y'],
            ['74', 'Ukraine', 'UA', 'UKR', 'N'],
            ['99', 'United Kingdom', 'GB', 'GBR', 'Y'],
        ];

        $now = now();
        foreach ($countries as [$code, $name, $a2, $a3, $natoMember]) {
            DB::table('nato_country_codes')->insertOrIgnore([
                'ncb_code'     => $code,
                'country_name' => $name,
                'iso_alpha2'   => $a2,
                'iso_alpha3'   => $a3,
                'nato_member'  => $natoMember,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nato_country_codes');
        Schema::dropIfExists('nato_nsn');
        Schema::dropIfExists('nato_ncage');
    }
};
