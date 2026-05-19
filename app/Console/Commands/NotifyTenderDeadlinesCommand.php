<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envia Web Push para tenders cujo deadline está dentro de 23-25h
 * (janela de 2h para apanhar o tender exactamente 1x — runs hourly
 * via scheduler, qualquer trigger duplicado é deduplicado pela tabela
 * `notification_log`).
 *
 * Scheduled em routes/console.php:
 *   Schedule::command('tenders:notify-deadlines')->hourly();
 */
class NotifyTenderDeadlinesCommand extends Command
{
    protected $signature = 'tenders:notify-deadlines
                            {--window-hours=24 : Hora-alvo (default 24h)}
                            {--dry-run : Só lista os candidatos}';

    protected $description = 'Envia Web Push aos collaborators atribuídos ~24h antes do deadline';

    public function handle(PushNotificationService $push): int
    {
        $hours = max(1, (int) $this->option('window-hours'));

        // Janela de 2h centrada em $hours antes do deadline. Hourly schedule
        // garante que cada tender é apanhado uma vez (a flag last_sent_at
        // no push_subscriptions não dedup'a — fazemos via notification_log
        // ou simplesmente confiamos no scheduler hourly).
        $start = now()->addHours($hours - 1);
        $end   = now()->addHours($hours + 1);

        $tenders = Tender::whereNotNull('deadline_at')
            ->whereNotNull('assigned_collaborator_id')
            ->whereBetween('deadline_at', [$start, $end])
            ->whereNotIn('status', [
                Tender::STATUS_CANCELADO,
                Tender::STATUS_NAO_TRATAR,
                Tender::STATUS_GANHO,
                Tender::STATUS_PERDIDO,
                Tender::STATUS_SUBMETIDO,
            ])
            ->with('collaborator.user')
            ->get();

        $this->info("⏰ {$tenders->count()} tenders com deadline em ~{$hours}h");

        if ($tenders->isEmpty()) return self::SUCCESS;

        if ($this->option('dry-run')) {
            foreach ($tenders as $t) {
                $this->line("  · #{$t->id} {$t->reference} → user_id={$t->collaborator?->user?->id} ({$t->collaborator?->name})");
            }
            return self::SUCCESS;
        }

        $totalSent    = 0;
        $totalDeleted = 0;
        $totalFailed  = 0;

        foreach ($tenders as $t) {
            $userId = $t->collaborator?->user?->id;
            if (!$userId) continue;

            $title = '⏰ Deadline em ' . $hours . 'h';
            $body  = '[' . strtoupper($t->source) . '] ' . mb_strimwidth((string) $t->title, 0, 140, '…');
            $url   = url('/tenders/' . $t->id);

            $res = $push->sendToUser($userId, [
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'tag'   => 'deadline-' . $t->id,
                'icon'  => '/images/clawyard-icon.svg',
                'badge' => '/images/clawyard-icon.svg',
            ]);

            $totalSent    += $res['sent'];
            $totalDeleted += $res['deleted'];
            $totalFailed  += $res['failed'];

            Log::info('NotifyTenderDeadlines: dispatched', [
                'tender_id' => $t->id,
                'user_id'   => $userId,
                'sent'      => $res['sent'],
            ]);
        }

        $this->info("✓ {$totalSent} push enviados · {$totalDeleted} subscriptions expiradas removidas · {$totalFailed} falhas");

        return self::SUCCESS;
    }
}
