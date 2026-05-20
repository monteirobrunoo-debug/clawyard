<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight "who else is here" presence tracker.
 *
 * No WebSockets — pure cache-based heartbeat. Every authenticated
 * page calls POST /api/presence/heartbeat every 30s with the current
 * URL. The cache stores per-user {url, last_seen_at, name, initials}
 * with a 90s TTL so a user that closes their tab disappears within
 * 1-2 minutes.
 *
 * GET /api/presence/who?url=/tenders/12 returns who else is currently
 * looking at that exact URL (excluding the caller).
 *
 * Storage: Cache::tags doesn't work on the file driver, but we use
 * Redis on production so a key-prefix scan via Cache::store()->keys()
 * isn't safe either. Instead we maintain a single index key
 * 'presence:active_user_ids' (TTL 120s) that the GET endpoint reads
 * to know which user keys to look up. Simpler than a tag scan + works
 * on every cache backend Laravel ships with.
 */
class PresenceController extends Controller
{
    private const TTL_SECONDS    = 90;
    private const INDEX_KEY      = 'presence:user_ids:v1';
    private const USER_KEY_FMT   = 'presence:u:%d:v1';

    public function heartbeat(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $url = trim((string) $request->input('url', ''));
        // Drop query string + hash — only the path tells us which page.
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $entry = [
            'user_id'  => $user->id,
            'name'     => $user->name,
            'initials' => $this->initialsFor($user->name),
            'url'      => $path,
            'seen_at'  => now()->toIso8601String(),
        ];
        Cache::put(sprintf(self::USER_KEY_FMT, $user->id), $entry, self::TTL_SECONDS);

        // Maintain a small index of active user IDs so the GET
        // endpoint doesn't need a key scan. We store a list with a
        // TTL slightly above the per-user TTL — the next heartbeat
        // refreshes it.
        $ids = Cache::get(self::INDEX_KEY, []);
        if (!in_array($user->id, $ids, true)) {
            $ids[] = $user->id;
        }
        Cache::put(self::INDEX_KEY, $ids, self::TTL_SECONDS + 30);

        return response()->json(['ok' => true]);
    }

    public function who(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $url  = (string) $request->input('url', '');
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $ids = Cache::get(self::INDEX_KEY, []);
        $now = now();
        $live = [];
        $cleanIds = [];
        foreach ($ids as $id) {
            if ((int) $id === (int) $user->id) {
                $cleanIds[] = $id;
                continue;   // never report the caller as "other"
            }
            $entry = Cache::get(sprintf(self::USER_KEY_FMT, $id));
            if (!$entry) continue;       // expired
            $cleanIds[] = $id;
            // Only include if they're on the SAME path right now.
            if (($entry['url'] ?? '') === $path) {
                $live[] = [
                    'user_id'  => $entry['user_id'],
                    'name'     => $entry['name'],
                    'initials' => $entry['initials'],
                    'seen_at'  => $entry['seen_at'],
                ];
            }
        }
        // Drop expired user_ids from the index so it stays small.
        if (count($cleanIds) !== count($ids)) {
            Cache::put(self::INDEX_KEY, $cleanIds, self::TTL_SECONDS + 30);
        }

        return response()->json([
            'path' => $path,
            'live' => $live,
        ]);
    }

    /**
     * GET /api/presence/online-now
     *
     * Lista global de TODOS os utilizadores humanos online agora,
     * independente da página em que estão. Diferente do who() que é
     * filtrado por path — este é para o header do dashboard mostrar
     * "Online agora: José Inácio, Eduardo Rio…".
     *
     * 2026-05-20: pedido directo ("subroposeste os nomes de quem está
     * online") — o user via "Marta CRM respondeu" 3× no ticker e queria
     * ver as pessoas reais que estão online.
     */
    public function onlineNow(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $ids = Cache::get(self::INDEX_KEY, []);
        $live = [];
        $cleanIds = [];
        foreach ($ids as $id) {
            $entry = Cache::get(sprintf(self::USER_KEY_FMT, $id));
            if (!$entry) continue;   // expired
            $cleanIds[] = $id;
            $live[] = [
                'user_id'    => $entry['user_id'],
                'name'       => $entry['name'],
                'initials'   => $entry['initials'],
                'seen_at'    => $entry['seen_at'],
                'is_self'    => (int) $entry['user_id'] === (int) $user->id,
            ];
        }
        if (count($cleanIds) !== count($ids)) {
            Cache::put(self::INDEX_KEY, $cleanIds, self::TTL_SECONDS + 30);
        }

        // Ordena: outros primeiro (alfabético), self no fim.
        usort($live, function ($a, $b) {
            if ($a['is_self'] !== $b['is_self']) return $a['is_self'] ? 1 : -1;
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return response()->json([
            'count' => count($live),
            'live'  => $live,
        ]);
    }

    private function initialsFor(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}
