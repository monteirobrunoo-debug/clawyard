<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── PSI Intelligence Bus — prune expired entries every hour ──────────────────
Schedule::command('shared-context:prune')->hourly();

// ── Agent-share retention — strip access logs older than the configured
// window (AGENT_SHARE_LOG_RETENTION_DAYS, default 90d) plus spent/expired
// OTP rows, so we don't hold PII (email, IP, UA) indefinitely.
Schedule::command('agentshares:cleanup')->dailyAt('03:20');

// ── Concursos (Tenders) — daily digest, weekdays only ─────────────────────
// User requirement: "Emails manhã e final de tarde com necessidade de
// actualização". Morning slot moved to 09:00 (2026-04-28) so it lands
// when people are at their desks, not pre-coffee at 07:30. Evening
// nudge stays at 17:00 to catch the ones that didn't move during the day.
//
// `timezone('Europe/Lisbon')` makes the clock walk-clock Lisbon, so
// daylight-saving transitions don't shift the send time.
// `weekdays()` keeps us out of people's inboxes on Saturdays and Sundays.
Schedule::command('tenders:send-digest --slot=morning')
    ->weekdays()
    ->timezone('Europe/Lisbon')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('tenders:send-digest --slot=evening')
    ->weekdays()
    ->timezone('Europe/Lisbon')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->runInBackground();

// ── Individual deadline alert — fires ~24h before each tender's deadline,
// exactly ONCE per tender lifetime (de-duped via deadline_alert_sent_at).
// Sent only to the assigned collaborator so we don't duplicate the digest.
//
// Hourly grain is fine: the 2h window (23–25h out) absorbs any drift, and
// once the flag is set the tender is skipped forever.
Schedule::command('tenders:send-deadline-alerts')
    ->hourly()
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();
