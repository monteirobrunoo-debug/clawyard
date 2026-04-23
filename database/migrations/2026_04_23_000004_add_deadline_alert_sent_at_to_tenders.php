<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot flag so the 1-day-before-deadline alert only fires ONCE per
 * tender. After the timestamp is set, the hourly scheduler skips the row;
 * after the deadline passes the tender stops being eligible anyway, so we
 * never need to reset this column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->timestamp('deadline_alert_sent_at')->nullable()->after('last_digest_sent_at');
            // Index on deadline_at alone is enough — the alert query is
            // "deadline_at BETWEEN now+23h and now+25h AND
            //  deadline_alert_sent_at IS NULL".
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->dropColumn('deadline_alert_sent_at');
        });
    }
};
