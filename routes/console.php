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
// User requirement: "Emails mahã e final de tarder com necessidade de
// actualização". Morning pushes first thing before work starts; evening
// nudge catches the ones that didn't move during the day.
//
// `timezone('Europe/Lisbon')` makes the clock walk-clock Lisbon, so
// daylight-saving transitions don't shift the send time.
// `weekdays()` keeps us out of people's inboxes on Saturdays and Sundays.
Schedule::command('tenders:send-digest --slot=morning')
    ->weekdays()
    ->timezone('Europe/Lisbon')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('tenders:send-digest --slot=evening')
    ->weekdays()
    ->timezone('Europe/Lisbon')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->runInBackground();
