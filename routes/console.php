<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── PSI Intelligence Bus — prune expired entries every hour ──────────────────
Schedule::command('shared-context:prune')->hourly();

// ── Quantum digest pre-warm (Bruno fix 2026-05-29) ───────────────────────────
// Gera o digest científico do Prof. Quantum Leap às 06:00 e cacheia em Redis
// (RunQuantumDigestJob, TTL 25h). Quando o user clica "Digest de hoje" durante
// o dia, o QuantumAgent::stream() serve do cache instantaneamente em vez de
// fazer 4 fetches + Anthropic inline (~165s) que cortavam no Cloudflare 100s.
// withoutOverlapping evita 2 jobs simultâneos; runInBackground não bloqueia
// o scheduler tick.
Schedule::call(function () {
    \App\Jobs\RunQuantumDigestJob::dispatch(null);
})->dailyAt('06:00')->name('quantum-digest-prewarm')->withoutOverlapping();

// ── Acingov concursos pre-warm (cada 4h) ─────────────────────────────────────
// Os 6 fetches (Acingov + TED + UNGM + base.gov.pt + SAM.gov + EU Funding) +
// análise Anthropic demoravam >180s e o Octane matava o worker
// (OCTANE_MAX_EXECUTION_TIME=180) → "Error in input stream". RunAcingovSearchJob
// corre no queue worker e cacheia em Redis (TTL 4h); o AcingovAgent::stream()
// serve do cache instantaneamente. Cada 4h porque concursos públicos mudam mais
// que o digest diário do Quantum.
Schedule::call(function () {
    \App\Jobs\RunAcingovSearchJob::dispatch(null);
})->cron('0 */4 * * *')->name('acingov-prewarm')->withoutOverlapping();

// ── Queue worker via scheduler (fallback enquanto não há supervisor daemon) ──
// 2026-05-19 — pedido directo do operador para fazer as recomendações ASAP.
// Sem Forge API token não posso criar daemon Supervisor; entretanto o
// scheduler corre 'queue:work --stop-when-empty' a cada minuto via cron
// schedule:run. withoutOverlapping(60) impede 2 instâncias em paralelo.
// Quando tiveres tempo, adicionar daemon Supervisor via Forge UI:
//   Site → Daemons → Add → command: `php artisan queue:work --queue=high,default --tries=3 --timeout=120 --memory=256`
//   user: forge · directory: /home/forge/clawyard.partyard.eu/current
// Aí remove este Schedule (ou mantém como safety net).
Schedule::command('queue:work --queue=high,default --tries=3 --timeout=110 --max-time=55 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

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

// ── Periodic SAP-to-clawyard sync — every 15 minutes ─────────────────────
// Pulls Status + Remarks from SAP B1 for every tender with sap_opp linked,
// keeping the dashboard close to live with SAP. Skips overwriting local
// notes when the user has unsynced edits (last conflict-resolution wins
// on the user's side; SAP can be re-pushed via the existing notes-edit
// flow). Per-run cost: ~5s wall + ~0 USD (no LLM, just SAP B1 OData).
Schedule::command('tenders:sync-from-sap')
    ->everyFifteenMinutes()
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

// ── Supplier email finder — daily 03:30 Lisbon ───────────────────────────
// After the general enrichment fills websites, this scrapes each
// supplier's contact pages for emails, then falls back to MX-verified
// candidates (sales@, info@, contact@, …). 30 per night so the slow
// HTTP probing doesn't pile up; suppliers attempted in last 30d skip
// to save cycles. Zero LLM cost, zero Tavily cost — just outbound HTTP.
Schedule::command('suppliers:find-emails --limit=30')
    ->dailyAt('03:30')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Anthropic Batch nightly multi-agent — DESLIGADO 2026-05-22 ────────────
// Pedido directo: "cancela a utilizacao dos agentes na analise dos
// processos, tem de ser manual o custo dos tokens foi muito grande".
// 5 calls de €25-29 numa só hora (01:56 hoje) → ~€130/noite só do batch.
// Agora análise multi-agente SÓ via botão manual no tender. Para reactivar
// no futuro, descomenta as duas Schedule:: abaixo.
//
// Schedule::command('analysis:submit-batch --max=20 --stale-days=14')
//     ->dailyAt('23:30')
//     ->timezone('Europe/Lisbon')
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Schedule::command('analysis:collect-batches --max=20')
//     ->hourly()
//     ->withoutOverlapping()
//     ->runInBackground();

// ── Web-intel re-sync — sábados 04:30 Lisbon ─────────────────────────────
// 2026-05-21: re-corre Tavily + Claude para suppliers sincronizados há
// >30 dias. Websites mudam (catálogos atualizados, OEMs novos, brands
// adicionadas). Sábados às 04:30 não compete com outras tarefas.
//
// Custo: ~$0.01 por supplier processado. 873 totais − 155 cat
// restritas = 718 max. Em 30d só os que ficam stale (~25/semana) →
// ~$0.25/semana. Trivial.
Schedule::command('suppliers:sync-web-intel --stale')
    ->saturdays()
    ->at('04:30')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Auto-extract organizational knowledge — every 30 min ──────────────────
// 2026-05-22: varre conversas com novas mensagens assistant desde a última
// run, dispatch job per conversa para Haiku analisar + gravar factos em
// organizational_knowledge. Custo: ~$0.0005 por conversa COM factos.
// Job em queue 'low' — não compete com chats em curso.
Schedule::command('knowledge:auto-extract --max=15')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// ── Token budget alert — diário 08:00 Lisbon ──────────────────────────────
// 2026-05-22: pool €150/mês partilhado. Cron diário verifica thresholds
// (80% / 100%) e dispara email ao admin. Idempotente — flags
// notified_at_80/100 evitam re-envio no mesmo período.
Schedule::command('tokens:status --notify --limit=1')
    ->dailyAt('08:00')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping();

// ── Supplier directory enrichment — daily 02:50 Lisbon ───────────────────
// Walks suppliers needing web contact info (no email yet, or missing
// website, or stale > 30 days) and runs Tavily + Claude to fill in
// website / primary_email / additional_emails / phones. 50 per run so
// the 805-row directory enriches in ~16 days without spiking quota,
// and so any one daily run is bounded at ≈$0.30.
//
// Skips blacklisted + chronic misses (≥3 attempts with no email).
// 02:50 sits BEFORE the 09:00 tenders digest so by morning the
// directory already has the freshest contacts populated.
Schedule::command('suppliers:enrich --limit=50')
    ->dailyAt('02:50')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Lead outreach drafter — daily 08:30 Lisbon, weekdays only ────────────
// Walks confident leads (score>70) without an existing draft and
// generates a cold-outreach email via Anthropic. The drafts surface
// in /leads/{id} for the manager to review/edit/approve/send.
//
// 08:30 lands BEFORE the tenders digest (09:00) so by the time the
// manager opens the daily digest, fresh outreach drafts are ready
// alongside the tender activity. Weekdays-only because nobody approves
// outreach on Saturday morning.
//
// Per-run cost: ~$0.007 per lead in tokens. With our typical 5–20
// confident leads/week this is well under $1/month.
Schedule::command('leads:draft-outreach --limit=20')
    ->weekdays()
    ->dailyAt('08:30')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Weekly digest — sextas 09:00 Lisboa ──────────────────────────────────
// Resumo semanal de actividade por user (concursos trabalhados/submetidos,
// agentes mais usados, PDFs, custo IA, A-fazer). Idempotente por
// (user, week_start) — re-runs no mesmo dia não duplicam. Skip silencioso
// para users sem actividade na semana.
Schedule::command('clawyard:send-weekly-digest')
    ->fridays()
    ->at('09:00')
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();

// ── Relatório semanal de actividade ClawYard — segundas 08:00 Lisboa ─────
// Manda a cada utilizador activo um resumo da sua semana: pontos ganhos,
// nível actual, streak, agentes mais usados, ranking. Skip silencioso
// para quem teve 0 pts (férias / fim-de-semana inactivo). Per-run cost:
// só SMTP, sem LLM. Idempotência: a semana é fixa pelo intervalo de
// query (weekAgo); re-runs no mesmo dia mandam o mesmo conteúdo. Aceite
// — Laravel scheduler garante apenas 1 corrida por minuto agendado.
Schedule::command('clawyard:weekly-activity')
    ->mondays()
    ->at('08:00')
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

// ── Web Push deadline notifications — hourly, ~24h antes do deadline.
// Complementa o email alert acima (que é 1×/lifetime). Push acorda o
// telemóvel mesmo sem mail aberto. Janela 23-25h apanha cada tender 1×.
Schedule::command('tenders:notify-deadlines --window-hours=24')
    ->hourly()
    ->timezone('Europe/Lisbon')
    ->withoutOverlapping()
    ->runInBackground();
