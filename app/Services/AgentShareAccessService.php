<?php

namespace App\Services;

use App\Models\AgentShare;
use App\Models\AgentShareAccessLog;
use App\Models\AgentShareOtp;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AgentShareAccessService — end-to-end gatekeeper for public share links.
 *
 * Responsibilities:
 *   - issue/verify 6-digit one-time passwords sent to the client email
 *   - pin the authorised session to a browser fingerprint
 *   - emit a fresh OTP challenge when a new device is detected
 *   - persist an audit trail (AgentShareAccessLog)
 *   - notify the share owner on every successful access
 *
 * All public methods are safe to call without exceptions — failures are
 * logged and degrade gracefully (stream still works even if the
 * notification pipeline is down).
 */
class AgentShareAccessService
{
    // How long an OTP stays valid once issued.
    public const OTP_TTL_MINUTES = 10;

    // How long a verified session is trusted before a fresh OTP is required.
    public const SESSION_TTL_HOURS = 24;

    // Max OTP attempts per code before it is burned.
    public const MAX_OTP_ATTEMPTS = 5;

    // Session cache key prefix — stored server-side (NOT in browser).
    private const SESSION_CACHE_PREFIX = 'share_session:';

    // ── OTP issuance ─────────────────────────────────────────────────────────

    /**
     * Generate and email a 6-digit OTP to the address the client supplied.
     * The email MUST match $share->client_email (case-insensitive) or we
     * silently succeed to avoid leaking whether a link was issued.
     *
     * Returns true if an OTP was actually sent.
     */
    public function issueOtp(AgentShare $share, string $email, string $sessionId, Request $request): bool
    {
        $email = strtolower(trim($email));

        // Multi-recipient aware check: the authorised set is client_email ∪
        // additional_emails. Any email OUTSIDE the set is silently dropped
        // (log denied + return true) so attackers can't probe which addresses
        // are on the allowlist.
        if ($email === '' || !$share->isAuthorisedEmail($email)) {
            $this->log($share, 'otp_requested', 'denied', $email, $sessionId, $request, 'email not authorised');
            return true;
        }

        // Throttle: no more than 3 OTPs per (share, session) in a 10-min window.
        $throttleKey = 'share_otp_rate:' . $share->id . ':' . substr(hash('sha256', $sessionId), 0, 16);
        $count = (int) Cache::get($throttleKey, 0);
        if ($count >= 3) {
            $this->log($share, 'otp_requested', 'denied', $email, $sessionId, $request, 'rate limited');
            return false;
        }
        Cache::put($throttleKey, $count + 1, now()->addMinutes(10));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        AgentShareOtp::create([
            'agent_share_id' => $share->id,
            'email'          => $email,
            'session_id'     => $sessionId,
            'code_hash'      => hash('sha256', $code),
            'attempts'       => 0,
            'expires_at'     => now()->addMinutes(self::OTP_TTL_MINUTES),
            'ip'             => $request->ip(),
        ]);

        $this->sendOtpEmail($share, $email, $code);
        $this->log($share, 'otp_requested', 'allowed', $email, $sessionId, $request);

        return true;
    }

    /**
     * Validate the OTP entered by the client. On success we mint a trusted
     * session token and (if locking is enabled) pin the device fingerprint.
     */
    public function verifyOtp(AgentShare $share, string $email, string $sessionId, string $code, Request $request): bool
    {
        $email = strtolower(trim($email));

        $otp = AgentShareOtp::where('agent_share_id', $share->id)
            ->where('session_id', $sessionId)
            ->where('email', $email)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp) {
            $this->log($share, 'otp_failed', 'denied', $email, $sessionId, $request, 'no pending otp');
            return false;
        }

        if (!$otp->isAlive()) {
            $this->log($share, 'otp_failed', 'denied', $email, $sessionId, $request, 'expired or exhausted');
            return false;
        }

        // Count the attempt even if it misses — burn brute-forcers quickly.
        $otp->increment('attempts');

        if (!$otp->matches($code)) {
            $this->log($share, 'otp_failed', 'denied', $email, $sessionId, $request, 'wrong code');
            return false;
        }

        $otp->update(['used_at' => now()]);

        $fingerprint = $this->fingerprintFor($request);

        // Pin the device if this is the first successful login and the
        // share is configured for device locking. Subsequent devices will
        // have to pass a fresh OTP challenge.
        $this->storeSession($share, $sessionId, $email, $fingerprint);

        $this->log($share, 'otp_verified', 'allowed', $email, $sessionId, $request);
        $this->notifyOwner($share, $email, $request, 'acesso autenticado');

        return true;
    }

    // ── Session validation (called on show() + stream()) ─────────────────────

    /**
     * Decide whether the current request already holds a valid session.
     * Returns one of:
     *   'ok'            — session is fresh; allow the request through
     *   'otp_required'  — no session or expired; show OTP challenge
     *   'new_device'    — session exists but fingerprint changed; challenge again
     *   'revoked'       — owner revoked the share since last visit
     */
    public function sessionStatus(AgentShare $share, string $sessionId, Request $request): string
    {
        if ($share->isRevoked()) return 'revoked';

        $session = $this->loadSession($share, $sessionId);
        if (!$session) return 'otp_required';

        // Force a new challenge after SESSION_TTL_HOURS.
        if (($session['issued_at'] ?? 0) < now()->subHours(self::SESSION_TTL_HOURS)->timestamp) {
            return 'otp_required';
        }

        if ($share->lock_to_device) {
            $currentFp = $this->fingerprintFor($request);
            if (!isset($session['fingerprint']) || !hash_equals($session['fingerprint'], $currentFp)) {
                $this->log(
                    $share,
                    'blocked_device',
                    'denied',
                    $session['email'] ?? null,
                    $sessionId,
                    $request,
                    'fingerprint mismatch'
                );
                return 'new_device';
            }
        }

        return 'ok';
    }

    /**
     * Record a stream request for the audit trail. Called after auth passes.
     */
    public function recordStream(AgentShare $share, string $sessionId, Request $request): void
    {
        $session = $this->loadSession($share, $sessionId);
        $this->log(
            $share,
            'stream',
            'allowed',
            $session['email'] ?? null,
            $sessionId,
            $request,
            null
        );
    }

    public function revoke(AgentShare $share, Request $request, ?string $reason = null): void
    {
        $share->revoke($reason);
        $this->log($share, 'revoked', 'allowed', null, null, $request, $reason);
    }

    // ── Portal-level OTP ─────────────────────────────────────────────────────
    //
    // When multiple shares are created for the same client they share a
    // portal_token. A single OTP challenge unlocks every agent in the
    // bundle — the visitor only types one code per session.

    public function issuePortalOtp(string $portalToken, string $email, string $sessionId, Request $request): bool
    {
        $email      = strtolower(trim($email));
        $shares     = AgentShare::where('portal_token', $portalToken)->get();
        if ($shares->isEmpty()) return true; // silent — don't leak existence

        // All shares in a portal bundle must share the same client_email;
        // compare against the first as the canonical authorised address.
        $expected = strtolower(trim($shares->first()->client_email ?? ''));
        if (!$expected || !hash_equals($expected, $email)) {
            foreach ($shares as $s) {
                $this->log($s, 'otp_requested', 'denied', $email, $sessionId, $request, 'portal email mismatch');
            }
            return true;
        }

        // Throttle per (portal, browser session)
        $key   = 'portal_otp_rate:' . $portalToken . ':' . substr(hash('sha256', $sessionId), 0, 16);
        $count = (int) Cache::get($key, 0);
        if ($count >= 3) return false;
        Cache::put($key, $count + 1, now()->addMinutes(10));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store one OTP row per share for the audit trail — quick, indexed.
        foreach ($shares as $s) {
            AgentShareOtp::create([
                'agent_share_id' => $s->id,
                'email'          => $email,
                'session_id'     => 'portal:' . $portalToken . ':' . $sessionId,
                'code_hash'      => hash('sha256', $code),
                'attempts'       => 0,
                'expires_at'     => now()->addMinutes(self::OTP_TTL_MINUTES),
                'ip'             => $request->ip(),
            ]);
        }

        // Send the code once — preferred contact is the owner-configured
        // notify_email or fall back to the client_email itself.
        $this->sendOtpEmail($shares->first(), $email, $code);

        foreach ($shares as $s) {
            $this->log($s, 'otp_requested', 'allowed', $email, $sessionId, $request, 'portal');
        }
        return true;
    }

    public function verifyPortalOtp(string $portalToken, string $email, string $sessionId, string $code, Request $request): bool
    {
        $email  = strtolower(trim($email));
        $shares = AgentShare::where('portal_token', $portalToken)->get();
        if ($shares->isEmpty()) return false;

        $portalSession = 'portal:' . $portalToken . ':' . $sessionId;

        $otp = AgentShareOtp::whereIn('agent_share_id', $shares->pluck('id'))
            ->where('session_id', $portalSession)
            ->where('email', $email)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp || !$otp->isAlive()) {
            foreach ($shares as $s) {
                $this->log($s, 'otp_failed', 'denied', $email, $sessionId, $request, 'portal ' . ($otp ? 'expired' : 'missing'));
            }
            return false;
        }

        $otp->increment('attempts');
        if (!$otp->matches($code)) {
            foreach ($shares as $s) {
                $this->log($s, 'otp_failed', 'denied', $email, $sessionId, $request, 'portal wrong code');
            }
            return false;
        }

        // Burn all codes issued in this batch — they have all been consumed.
        AgentShareOtp::whereIn('agent_share_id', $shares->pluck('id'))
            ->where('session_id', $portalSession)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $fingerprint = $this->fingerprintFor($request);
        $this->storePortalSession($portalToken, $sessionId, $email, $fingerprint);

        foreach ($shares as $s) {
            $this->log($s, 'otp_verified', 'allowed', $email, $sessionId, $request, 'portal');
        }

        $this->notifyOwner($shares->first(), $email, $request, 'portal autenticado');
        return true;
    }

    /**
     * Same semantics as sessionStatus() but keyed to a portal_token.
     */
    public function portalSessionStatus(string $portalToken, string $sessionId, Request $request): string
    {
        $shares = AgentShare::where('portal_token', $portalToken)->get();
        if ($shares->isEmpty()) return 'revoked';

        // If every sibling has been revoked, the whole portal is dead.
        if ($shares->every(fn($s) => $s->isRevoked())) return 'revoked';

        $session = Cache::get('share_session:portal:' . $portalToken . ':' . $sessionId);
        if (!is_array($session)) return 'otp_required';

        if (($session['issued_at'] ?? 0) < now()->subHours(self::SESSION_TTL_HOURS)->timestamp) {
            return 'otp_required';
        }

        // Device lock is inherited if ANY share in the bundle requires it.
        $lock = $shares->contains(fn($s) => (bool) $s->lock_to_device);
        if ($lock) {
            $currentFp = $this->fingerprintFor($request);
            if (!isset($session['fingerprint']) || !hash_equals($session['fingerprint'], $currentFp)) {
                return 'new_device';
            }
        }

        return 'ok';
    }

    private function storePortalSession(string $portalToken, string $sessionId, string $email, string $fingerprint): void
    {
        Cache::put(
            'share_session:portal:' . $portalToken . ':' . $sessionId,
            [
                'email'       => $email,
                'fingerprint' => $fingerprint,
                'issued_at'   => now()->timestamp,
            ],
            now()->addHours(self::SESSION_TTL_HOURS)
        );
    }

    /**
     * Does the current browser already hold a valid portal session that
     * covers this specific agent share? Used to skip the per-agent OTP
     * challenge once the parent portal has been authenticated.
     */
    public function portalCoversShare(AgentShare $share, string $sessionId, Request $request): bool
    {
        if (!$share->portal_token) return false;
        return $this->portalSessionStatus($share->portal_token, $sessionId, $request) === 'ok';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a device fingerprint from request-stable signals.
     * We do NOT trust the raw User-Agent alone — we combine it with the
     * Accept-Language header and hashes of a couple of sec-ch-ua hints.
     * Fingerprint is stable across page reloads on the same browser, but
     * changes across devices/browsers.
     */
    public function fingerprintFor(Request $request): string
    {
        $parts = [
            $request->userAgent() ?? '',
            $request->header('Accept-Language', ''),
            $request->header('Sec-Ch-Ua-Platform', ''),
            $request->header('Sec-Ch-Ua', ''),
            $request->header('Sec-Ch-Ua-Mobile', ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    private function storeSession(AgentShare $share, string $sessionId, string $email, string $fingerprint): void
    {
        Cache::put(
            self::SESSION_CACHE_PREFIX . $share->id . ':' . $sessionId,
            [
                'email'       => $email,
                'fingerprint' => $fingerprint,
                'issued_at'   => now()->timestamp,
            ],
            now()->addHours(self::SESSION_TTL_HOURS)
        );
    }

    private function loadSession(AgentShare $share, string $sessionId): ?array
    {
        $v = Cache::get(self::SESSION_CACHE_PREFIX . $share->id . ':' . $sessionId);
        return is_array($v) ? $v : null;
    }

    private function log(
        AgentShare $share,
        string $event,
        string $status,
        ?string $email,
        ?string $sessionId,
        Request $request,
        ?string $note = null
    ): void {
        try {
            AgentShareAccessLog::create([
                'agent_share_id' => $share->id,
                'email'          => $email,
                'session_id'     => $sessionId,
                'fingerprint'    => $this->fingerprintFor($request),
                'ip'             => $request->ip(),
                'country'        => $this->countryFor($request),
                'user_agent'     => mb_substr((string) $request->userAgent(), 0, 500),
                'event'          => $event,
                'status'         => $status,
                'note'           => $note,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AgentShareAccess: log failed — ' . $e->getMessage());
        }
    }

    private function countryFor(Request $request): ?string
    {
        // Cloudflare forwards the country code for free on every request.
        // Fall back to null if we're not behind CF.
        $cf = $request->header('CF-IPCountry');
        return $cf && strlen($cf) <= 3 ? strtoupper($cf) : null;
    }

    // ── Messaging ────────────────────────────────────────────────────────────

    private function sendOtpEmail(AgentShare $share, string $email, string $code): void
    {
        $agentMeta = AgentShare::agentMeta()[$share->agent_key] ?? ['name' => $share->agent_key];
        $agentName = $agentMeta['name'] ?? $share->agent_key;
        $minutes   = self::OTP_TTL_MINUTES;

        $body = "Código de acesso ao agente {$agentName} (ClawYard):\n\n"
              . "   {$code}\n\n"
              . "Este código é válido por {$minutes} minutos e só pode ser usado uma vez.\n"
              . "Se não foste tu a pedir, ignora este email — ninguém consegue entrar sem o código.\n\n"
              . "— ClawYard / PartYard";

        $subject  = "[ClawYard] Código de acesso — {$agentName}";
        $fromAddr = config('mail.from.address', 'no-reply@hp-group.org');
        $fromName = config('mail.from.name', 'HP-Group / ClawYard');

        // Defer the SMTP handshake until AFTER the HTTP response has been
        // flushed to the browser. The OTP code is already in the DB, so the
        // frontend can advance to the "check your inbox" screen instantly;
        // the email actually leaves the server a few seconds later on the
        // same PHP worker — no queue worker required.
        dispatch(function () use ($email, $body, $subject, $fromAddr, $fromName) {
            try {
                Mail::raw($body, function ($msg) use ($email, $subject, $fromAddr, $fromName) {
                    $msg->to($email)->subject($subject)->from($fromAddr, $fromName);
                });
            } catch (\Throwable $e) {
                Log::warning('AgentShareAccess: OTP email failed — ' . $e->getMessage());
            }
        })->afterResponse();
    }

    private function notifyOwner(AgentShare $share, string $email, Request $request, string $event): void
    {
        if (!$share->notify_on_access) return;

        $agentMeta = AgentShare::agentMeta()[$share->agent_key] ?? ['name' => $share->agent_key];
        $agentName = $agentMeta['name'] ?? $share->agent_key;
        $ip        = $request->ip() ?? '?';
        $country   = $this->countryFor($request) ?? '?';
        $ua        = mb_substr((string) $request->userAgent(), 0, 120);
        $when      = now()->format('d/m/Y H:i');
        $revokeUrl = rtrim(config('app.url'), '/') . '/shares';

        $subject = "[ClawYard] {$email} abriu '{$agentName}'";
        $body    = "Evento: {$event}\n"
                 . "Agente: {$agentName}\n"
                 . "Cliente: {$share->client_name} <{$email}>\n"
                 . "Quando: {$when}\n"
                 . "IP: {$ip} (país {$country})\n"
                 . "Dispositivo: {$ua}\n\n"
                 . "Se não reconheces este acesso, revoga o link imediatamente:\n"
                 . "{$revokeUrl}\n\n"
                 . "— ClawYard / PartYard";

        $notifyEmail = $share->notify_email ?: optional($share->creator)->email;
        if ($notifyEmail) {
            $fromAddr = config('mail.from.address', 'no-reply@hp-group.org');
            $fromName = config('mail.from.name', 'HP-Group / ClawYard');
            dispatch(function () use ($notifyEmail, $subject, $body, $fromAddr, $fromName) {
                try {
                    Mail::raw($body, function ($msg) use ($notifyEmail, $subject, $fromAddr, $fromName) {
                        $msg->to($notifyEmail)->subject($subject)->from($fromAddr, $fromName);
                    });
                } catch (\Throwable $e) {
                    Log::warning('AgentShareAccess: owner email failed — ' . $e->getMessage());
                }
            })->afterResponse();
        }

        if ($share->notify_whatsapp) {
            $waTo   = $share->notify_whatsapp;
            $waText = "🔔 *ClawYard* — {$email} abriu *{$agentName}*\n{$when} · IP {$ip} ({$country})\nRevogar: {$revokeUrl}";
            // Inline the Graph API call so the deferred closure stays
            // serialisable (no captured $this with its Guzzle client).
            $token   = config('services.whatsapp.token');
            $phoneId = config('services.whatsapp.phone_id');
            if ($token && $phoneId) {
                dispatch(function () use ($waTo, $waText, $token, $phoneId) {
                    try {
                        $client = new \GuzzleHttp\Client(['base_uri' => 'https://graph.facebook.com/v18.0/', 'timeout' => 8]);
                        $client->post("{$phoneId}/messages", [
                            'headers' => [
                                'Authorization' => "Bearer {$token}",
                                'Content-Type'  => 'application/json',
                            ],
                            'json' => [
                                'messaging_product' => 'whatsapp',
                                'to'                => preg_replace('/[^\d+]/', '', $waTo),
                                'type'              => 'text',
                                'text'              => ['body' => $waText],
                            ],
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('AgentShareAccess: WhatsApp notify failed — ' . $e->getMessage());
                    }
                })->afterResponse();
            }
        }
    }

    private function sendWhatsAppNotification(string $to, string $text): void
    {
        $token   = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_id');
        if (!$token || !$phoneId) return;

        try {
            $client = new Client(['base_uri' => 'https://graph.facebook.com/v18.0/', 'timeout' => 8]);
            $client->post("{$phoneId}/messages", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to'                => preg_replace('/[^\d+]/', '', $to),
                    'type'              => 'text',
                    'text'              => ['body' => $text],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('AgentShareAccess: WhatsApp notification failed — ' . $e->getMessage());
        }
    }
}
