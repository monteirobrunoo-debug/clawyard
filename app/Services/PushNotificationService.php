<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envia Web Push notifications via VAPID + minishlink/web-push.
 *
 * VAPID keys são geradas uma vez via `php artisan push:generate-vapid`
 * e guardadas em config/services.php → push.vapid.public/private.
 *
 * Comportamento defensivo:
 *   • Subscriptions com endpoint expirado (HTTP 410 do vendor) são
 *     deleted automaticamente.
 *   • Falhas individuais são logadas mas não rebentam a iteração.
 */
class PushNotificationService
{
    public function __construct(
        private ?string $publicKey  = null,
        private ?string $privateKey = null,
        private ?string $subject    = null,
    ) {
        $this->publicKey  ??= (string) config('services.push.vapid.public', '');
        $this->privateKey ??= (string) config('services.push.vapid.private', '');
        $this->subject    ??= (string) config('services.push.vapid.subject', 'mailto:bruno.monteiro@hp-group.org');
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    /**
     * Envia uma notification para todos os subscriptions de 1 user.
     *
     * @param  int    $userId
     * @param  array  $payload  ['title' => ..., 'body' => ..., 'url' => ..., 'tag' => ...]
     * @return array{sent:int, deleted:int, failed:int}
     */
    public function sendToUser(int $userId, array $payload): array
    {
        if (!$this->isConfigured()) {
            Log::warning('PushNotification: VAPID keys not configured');
            return ['sent' => 0, 'deleted' => 0, 'failed' => 0];
        }

        $subs = PushSubscription::where('user_id', $userId)->get();
        if ($subs->isEmpty()) return ['sent' => 0, 'deleted' => 0, 'failed' => 0];

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $this->subject,
                'publicKey'  => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            $webPush->queueNotification(
                Subscription::create($sub->toWebPushArray()),
                $payloadJson
            );
        }

        $sent = 0; $deleted = 0; $failed = 0;
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;
                PushSubscription::where('endpoint', $endpoint)
                    ->update(['last_sent_at' => now()]);
                continue;
            }

            // 404/410: subscription killed by vendor — remove.
            $statusCode = $report->getResponse()?->getStatusCode();
            if (in_array($statusCode, [404, 410], true)) {
                PushSubscription::where('endpoint', $endpoint)->delete();
                $deleted++;
            } else {
                $failed++;
                Log::warning('PushNotification: send failed', [
                    'endpoint' => mb_substr($endpoint, 0, 80),
                    'status'   => $statusCode,
                    'reason'   => $report->getReason(),
                ]);
            }
        }

        return compact('sent', 'deleted', 'failed');
    }
}
