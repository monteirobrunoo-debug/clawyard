<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserWeeklyDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the Friday weekly digest to every active user that hasn't
 * opted out. Idempotent per (user, week_start) — safe to re-run.
 *
 * Schedule (routes/console.php):
 *   ->everyFriday()->at('09:00')->timezone('Europe/Lisbon')
 *
 * Manual run:
 *   php artisan clawyard:send-weekly-digest
 *   php artisan clawyard:send-weekly-digest --user=11   (single user)
 *   php artisan clawyard:send-weekly-digest --dry-run   (build but don't send)
 */
class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'clawyard:send-weekly-digest
                            {--user=  : send to a single user_id}
                            {--dry-run : build digest but skip the actual email send}';
    protected $description = 'Send Friday weekly activity digest to users';

    public function handle(UserWeeklyDigestService $svc): int
    {
        $weekStart = now()->copy()->startOfWeek()->startOfDay();   // Monday

        $query = User::query()
            ->where('is_active', true)
            ->where('weekly_digest_enabled', true)
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($singleId = (int) $this->option('user')) {
            $query->where('id', $singleId);
        }

        $users = $query->get();
        $this->info("📊 Building digest for {$users->count()} user(s) · week {$weekStart->format('Y-W')}");

        $sent = 0; $skipped = 0; $failed = 0;

        foreach ($users as $user) {
            // Idempotency: skip if already sent for this week
            $already = DB::table('user_weekly_digests')
                ->where('user_id', $user->id)
                ->where('week_start', $weekStart->toDateString())
                ->whereNotNull('sent_at')
                ->exists();

            if ($already && !$this->option('dry-run')) {
                $skipped++;
                continue;
            }

            try {
                $digest = $svc->buildFor($user, $weekStart);

                // Skip if absolutely nothing happened (don't spam empty digests)
                $hasContent = ($digest['core']['tenders_touched'] ?? 0) > 0
                           || ($digest['agents']['conversations'] ?? 0) > 0
                           || ($digest['rewards']['points_this_week'] ?? 0) > 0;
                if (!$hasContent) {
                    $skipped++;
                    $this->line("  · {$user->email} — sem actividade, skip");
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  ✓ {$user->email} — built (dry-run, not sent)");
                    $sent++;
                    continue;
                }

                Mail::send('emails.weekly-digest', ['digest' => $digest], function ($m) use ($user, $digest) {
                    $m->to($user->email, $user->name)
                      ->subject('📊 ClawYard — resumo semanal · ' . $digest['week']['start'] . '–' . $digest['week']['end']);
                });

                DB::table('user_weekly_digests')->updateOrInsert(
                    ['user_id' => $user->id, 'week_start' => $weekStart->toDateString()],
                    [
                        'stats'      => json_encode([
                            'core'    => $digest['core'],
                            'agents'  => $digest['agents']['top_agent'] ?? null,
                            'msgs'    => $digest['agents']['total_messages'] ?? 0,
                        ]),
                        'sent_at'    => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $sent++;
                $this->info("  ✓ {$user->email}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ {$user->email} — " . $e->getMessage());

                DB::table('user_weekly_digests')->updateOrInsert(
                    ['user_id' => $user->id, 'week_start' => $weekStart->toDateString()],
                    ['error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        $this->info("📨 Summary: {$sent} sent, {$skipped} skipped, {$failed} failed");
        return self::SUCCESS;
    }
}
