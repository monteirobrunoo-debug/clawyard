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

// ── D-MVP — credit agent wallets daily at 02:30 Lisbon ───────────────────
// Walks agent_metrics, computes the delta since last run, credits each
// wallet. Idempotent — re-running the same day is a no-op. Runs at
// 02:30 (post-midnight, well before the 09:00 morning digest) so the
// /agents/{key} performance card always shows fresh balance numbers
// when users open it during the day.
Schedule::command('agents:credit-wallets')
    ->dailyAt('02:30')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── D-MVP — robot-parts shopping round, weekly Monday 03:00 Lisbon ──────
// Each eligible agent (balance ≥ $2) convenes a 3-agent committee, picks
// a part, searches the web, generates CAD. Runs Mondays so by mid-week
// users can open /agents/{key} and see the new acquisitions.
//
// Cost per round: ~$0.005 in LLM tokens per participating agent + the
// part_orders.cost_usd debit (paper money — no real spend yet).
Schedule::command('agents:shop')
    ->weeklyOn(1, '03:00')   // Monday
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Phase B — robot research council, weekly Sunday 04:00 Lisbon ─────────
// 4 agents convene to research a robot improvement topic. Tavily web
// search + per-agent findings + lead synthesis with actionable proposals.
// Sunday so by Monday's shop round there's a fresh report informing
// the committees. ~$0.02 per session in LLM tokens.
Schedule::command('agents:research-council')
    ->weeklyOn(0, '04:00')   // Sunday
    ->timezone('Europe/Lisbon')
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
