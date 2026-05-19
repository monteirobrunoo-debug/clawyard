<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Subscribe / unsubscribe Web Push endpoints, 1 row por device por user.
 *
 * Browser side (em /resources/views/partials/push-subscribe.blade.php):
 *   PushManager.subscribe({applicationServerKey: VAPID_PUBLIC_KEY})
 *   → POST /push/subscribe com {endpoint, keys: {p256dh, auth}}
 *
 * Server side: idempotent (unique [user_id, endpoint]) — re-subscribe
 * apenas actualiza chaves se mudaram.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(403);

        $data = $request->validate([
            'endpoint'         => ['required', 'string', 'min:20', 'max:2000'],
            'keys'             => ['required', 'array'],
            'keys.p256dh'      => ['required', 'string', 'min:30', 'max:200'],
            'keys.auth'        => ['required', 'string', 'min:10', 'max:100'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        $sub = PushSubscription::updateOrCreate(
            ['user_id' => $user->id, 'endpoint' => $data['endpoint']],
            [
                'p256dh'           => $data['keys']['p256dh'],
                'auth'             => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent'       => mb_substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return response()->json([
            'ok' => true,
            'id' => $sub->id,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(403);

        $endpoint = (string) $request->input('endpoint', '');
        if ($endpoint === '') {
            return response()->json(['ok' => false, 'error' => 'endpoint_required'], 422);
        }

        $deleted = PushSubscription::where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
