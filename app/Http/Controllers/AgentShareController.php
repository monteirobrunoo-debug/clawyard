<?php

namespace App\Http\Controllers;

use App\Models\AgentShare;
use App\Agents\AgentManager;
use App\Services\AgentShareAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AgentShareController extends Controller
{
    // ── ADMIN: List all shares ───────────────────────────────────────────────
    public function index()
    {
        $shares = AgentShare::where('created_by', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('agent-shares.index', [
            'shares'    => $shares,
            'agentMeta' => AgentShare::agentMeta(),
        ]);
    }

    // ── ADMIN: Create new share ──────────────────────────────────────────────
    public function store(Request $request)
    {
        // SECURITY: client_email is now REQUIRED. Without it we can't enforce
        // the OTP challenge nor notify anyone. We also cap the default expiry
        // at 24 h so forgotten links don't hang around forever.
        $data = $request->validate([
            'agent_key'        => 'required|string|max:50',
            'client_name'      => 'required|string|max:100',
            'client_email'     => 'required|email|max:150',
            'password'         => 'nullable|string|min:4|max:100',
            'custom_title'     => 'nullable|string|max:100',
            'welcome_message'  => 'nullable|string|max:500',
            'show_branding'    => 'nullable|boolean',
            'allow_sap_access' => 'nullable|boolean',
            'require_otp'      => 'nullable|boolean',
            'lock_to_device'   => 'nullable|boolean',
            'notify_on_access' => 'nullable|boolean',
            'notify_email'     => 'nullable|email|max:150',
            'notify_whatsapp'  => 'nullable|string|max:30',
            'expires_at'       => 'nullable|date|after:now',
            // When the client is creating N shares in one batch, it passes
            // the same portal_token so the backend can group them into a
            // single /p/{portal_token} landing page.
            'portal_token'     => 'nullable|string|size:24|alpha_num',
        ]);

        $share = AgentShare::create([
            'token'            => AgentShare::generateToken(),
            'portal_token'     => $data['portal_token'] ?? null,
            'agent_key'        => $data['agent_key'],
            'client_name'      => $data['client_name'],
            'client_email'     => strtolower(trim($data['client_email'])),
            'password_hash'    => !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
            'custom_title'     => $data['custom_title'] ?? null,
            'welcome_message'  => $data['welcome_message'] ?? null,
            'show_branding'    => $request->boolean('show_branding', true),
            'allow_sap_access' => $request->boolean('allow_sap_access', false),
            'require_otp'      => $request->boolean('require_otp', true),
            'lock_to_device'   => $request->boolean('lock_to_device', true),
            'notify_on_access' => $request->boolean('notify_on_access', true),
            'notify_email'     => $data['notify_email'] ?? auth()->user()->email,
            'notify_whatsapp'  => $data['notify_whatsapp'] ?? null,
            'expires_at'       => $data['expires_at'] ?? now()->addHours(24),
            'created_by'       => auth()->id(),
        ]);

        // Notify the recipient with the share link. Errors must not break the
        // share creation flow — the link is already persisted and the owner
        // can still copy it manually from the UI.
        $emailSent = $this->sendShareEmail($share, $data['password'] ?? null);

        return response()->json([
            'ok'         => true,
            'url'        => $share->getUrl(),
            'portal_url' => $share->getPortalUrl(),
            'id'         => $share->id,
            'email_sent' => $emailSent,
        ]);
    }

    /**
     * Send the share link to the client via email. Uses MAIL_FROM_ADDRESS
     * (no-reply@hp-group.org) so recipients see a consistent HP-Group sender.
     * Returns true on success, false on any Mail failure.
     */
    private function sendShareEmail(AgentShare $share, ?string $password): bool
    {
        try {
            $meta     = AgentShare::agentMeta()[$share->agent_key] ?? [
                'name' => ucfirst($share->agent_key), 'emoji' => '🤖', 'color' => '#76b900',
                'role' => 'Agente ClawYard',
            ];
            $agentLbl  = trim(($meta['emoji'] ?? '🤖') . ' ' . ($meta['name'] ?? $share->agent_key));
            $agentRole = trim((string)($meta['role'] ?? ''));
            $url       = $share->getUrl();
            $expires  = $share->expires_at?->format('d/m/Y H:i') ?? '—';
            $ownerNm  = auth()->user()?->name  ?? 'Equipa HP-Group';
            $ownerEm  = auth()->user()?->email ?? config('mail.from.address', 'no-reply@hp-group.org');

            $passwordBlock = '';
            if ($password) {
                $passwordBlock = '<p style="margin:16px 0;padding:12px 16px;background:#fff7ed;border-left:3px solid #f59e0b;font-family:Menlo,monospace;font-size:14px;">'
                    . '🔑 <strong>Palavra-passe:</strong> ' . htmlspecialchars($password)
                    . '<br><span style="font-size:12px;color:#92400e;">Guarda esta palavra-passe — não a repetirei noutro email.</span>'
                    . '</p>';
            }

            $welcome = $share->welcome_message
                ? '<p style="margin:16px 0;padding:12px 16px;background:#f0f9ff;border-left:3px solid #0ea5e9;">'
                    . nl2br(htmlspecialchars($share->welcome_message))
                    . '</p>'
                : '';

            $clientName  = htmlspecialchars($share->client_name);
            $agentLblEsc = htmlspecialchars($agentLbl);
            $agentRoleEsc= htmlspecialchars($agentRole);
            $ownerNmEsc  = htmlspecialchars($ownerNm);
            $ownerEmEsc  = htmlspecialchars($ownerEm);
            $urlEsc      = htmlspecialchars($url);

            // Role card gives the client a one-line explanation of what the
            // agent actually does. Reduces confusion when many agents are
            // shared — the recipient no longer has to guess from the name.
            $roleBlock = $agentRole !== ''
                ? '<div style="margin:18px 0;padding:14px 18px;background:#f5fbe8;border-left:3px solid #76b900;border-radius:4px;">'
                    . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#4d7a00;font-weight:700;margin-bottom:4px;">O que faz este agente</div>'
                    . '<div style="font-size:14px;color:#1a2e05;">' . $agentRoleEsc . '</div>'
                    . '</div>'
                : '';

            $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222; line-height: 1.65; background: #f4f4f4; margin: 0; padding: 0; }
  .wrap { background: #fff; max-width: 640px; margin: 24px auto; padding: 32px; border-radius: 8px; }
  .hdr { border-bottom: 3px solid #76b900; padding-bottom: 14px; margin-bottom: 20px; }
  .logo { font-size: 22px; font-weight: bold; color: #76b900; }
  .btn { display: inline-block; background: #76b900; color: #fff !important; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 600; margin: 16px 0; }
  .meta { font-size: 13px; color: #555; }
  .footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr"><div class="logo">🐾 ClawYard</div></div>

  <p>Olá <strong>{$clientName}</strong>,</p>

  <p>Foi-te partilhado acesso ao agente <strong>{$agentLblEsc}</strong> na plataforma ClawYard.</p>

  {$roleBlock}

  {$welcome}

  <p style="text-align:center;">
    <a href="{$urlEsc}" class="btn">Abrir agente {$agentLblEsc}</a>
  </p>

  <p class="meta">Ou copia este link:<br>
    <a href="{$urlEsc}">{$urlEsc}</a>
  </p>

  {$passwordBlock}

  <table class="meta" cellpadding="0" cellspacing="0" style="margin-top:18px;">
    <tr><td style="padding:4px 12px 4px 0;"><strong>Válido até:</strong></td><td>{$expires}</td></tr>
    <tr><td style="padding:4px 12px 4px 0;"><strong>Enviado por:</strong></td><td>{$ownerNmEsc} &lt;{$ownerEmEsc}&gt;</td></tr>
  </table>

  <p style="margin-top:20px;font-size:12px;color:#666;">
    Se não estavas à espera deste acesso, podes simplesmente ignorar o email — o link expira automaticamente na data acima.
  </p>

  <div class="footer">
    ClawYard | IT Partyard LDA<br>
    Marine Spare Parts &amp; Technical Services<br>
    Setúbal, Portugal · no-reply@hp-group.org
  </div>
</div>
</body>
</html>
HTML;

            Mail::html($html, function ($mail) use ($share, $agentLbl) {
                $mail->to($share->client_email, $share->client_name)
                     ->from(
                         config('mail.from.address', 'no-reply@hp-group.org'),
                         config('mail.from.name', 'HP-Group / ClawYard')
                     )
                     ->subject('[ClawYard] Acesso ao agente ' . $agentLbl);
            });

            Log::info('AgentShare email sent', [
                'share_id'   => $share->id,
                'to'         => $share->client_email,
                'agent_key'  => $share->agent_key,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('AgentShare email failed: ' . $e->getMessage(), [
                'share_id' => $share->id ?? null,
                'to'       => $share->client_email ?? null,
            ]);
            return false;
        }
    }

    // ── ADMIN: Toggle active ─────────────────────────────────────────────────
    public function toggle(AgentShare $share)
    {
        $this->authorize_owner($share);
        $share->update(['is_active' => !$share->is_active]);
        return response()->json(['ok' => true, 'is_active' => $share->is_active]);
    }

    // ── ADMIN: Delete ────────────────────────────────────────────────────────
    public function destroy(AgentShare $share)
    {
        $this->authorize_owner($share);
        $share->delete();
        return response()->json(['ok' => true]);
    }

    // ── PUBLIC: Show shared agent chat page ──────────────────────────────────
    public function show(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->isValid()) {
            return view('agent-shares.expired');
        }

        // Password check — optional secondary factor on top of OTP.
        if ($share->password_hash) {
            $sessionKey = 'share_auth_' . $share->token;
            if (!session($sessionKey)) {
                return view('agent-shares.password', ['token' => $token]);
            }
        }

        // ── OTP + device-lock gate ──────────────────────────────────────────
        if ($share->require_otp) {
            $sessionId = $this->sharePublicSessionId($share);
            $svc       = app(AgentShareAccessService::class);

            // If this share belongs to a portal and the visitor has already
            // authenticated at the portal level, inherit that session and
            // skip the per-agent OTP challenge.
            if ($share->portal_token) {
                $portalSid = $this->sharePortalSessionId($share->portal_token);
                if ($svc->portalCoversShare($share, $portalSid, $request)) {
                    $meta = AgentShare::agentMeta()[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900'];
                    return view('agent-shares.chat', [
                        'share'     => $share,
                        'meta'      => $meta,
                        'share_sid' => $sessionId,
                    ]);
                }
            }

            $status = $svc->sessionStatus($share, $sessionId, $request);

            if ($status === 'revoked') {
                return view('agent-shares.expired', ['reason' => 'revoked']);
            }

            if (in_array($status, ['otp_required', 'new_device'], true)) {
                // Log the initial "open" hit so the owner sees attempts even
                // if the user never completes the challenge.
                $svc->recordStream($share, $sessionId, $request);

                // If the share belongs to a portal, redirect to the portal
                // OTP challenge so the visitor gets the unified flow.
                if ($share->portal_token) {
                    return redirect('/p/' . $share->portal_token);
                }

                return view('agent-shares.otp-challenge', [
                    'share'        => $share,
                    'new_device'   => $status === 'new_device',
                    'suggested'    => $share->client_email,
                ]);
            }
        }

        $meta = AgentShare::agentMeta()[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900'];

        // Expose the public session id to the chat view so the frontend can
        // send it back as a header on every /api/a/{token}/stream call.
        // This avoids having to rely on Laravel session cookies crossing the
        // web → api middleware group boundary (which fetch() sometimes drops
        // without `credentials: 'include'`).
        $shareSid = $this->sharePublicSessionId($share);

        return view('agent-shares.chat', [
            'share'     => $share,
            'meta'      => $meta,
            'share_sid' => $shareSid,
        ]);
    }

    // ── PUBLIC: Password verification ────────────────────────────────────────
    public function verifyPassword(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->password_hash) {
            return redirect('/a/' . $token);
        }

        $password = $request->input('password', '');
        if ($share->checkPassword($password)) {
            session(['share_auth_' . $token => true]);
            return redirect('/a/' . $token);
        }

        return back()->withErrors(['password' => 'Palavra-passe incorrecta.']);
    }

    // ── PUBLIC: Request OTP (sends 6-digit code to the registered email) ─────
    public function requestOtp(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();
        if (!$share->isValid()) abort(403, 'Link inválido.');

        $email = (string) $request->input('email', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['email' => 'Email inválido.']);
        }

        $sessionId = $this->sharePublicSessionId($share);
        app(AgentShareAccessService::class)->issueOtp($share, $email, $sessionId, $request);

        // Always report success to the client — we never reveal whether the
        // email matched, to avoid enumeration.
        return view('agent-shares.otp-challenge', [
            'share'        => $share,
            'otp_sent'     => true,
            'sent_to'      => $this->maskEmail($email),
        ]);
    }

    // ── PUBLIC: Verify OTP + create trusted session ──────────────────────────
    public function verifyOtp(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();
        if (!$share->isValid()) abort(403, 'Link inválido.');

        $email = strtolower(trim((string) $request->input('email', '')));
        $code  = preg_replace('/\D/', '', (string) $request->input('code', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 6) {
            return back()->withErrors(['code' => 'Código inválido.']);
        }

        $sessionId = $this->sharePublicSessionId($share);
        $ok = app(AgentShareAccessService::class)->verifyOtp($share, $email, $sessionId, $code, $request);

        if (!$ok) {
            return back()->withErrors(['code' => 'Código incorrecto ou expirado.']);
        }

        return redirect('/a/' . $token);
    }

    // ── OWNER: Revoke immediately ────────────────────────────────────────────
    public function revoke(Request $request, AgentShare $share)
    {
        $this->authorize_owner($share);
        app(AgentShareAccessService::class)->revoke($share, $request, $request->input('reason'));
        return response()->json(['ok' => true, 'revoked_at' => $share->refresh()->revoked_at]);
    }

    // ── OWNER: Access log ────────────────────────────────────────────────────
    public function accessLog(AgentShare $share)
    {
        $this->authorize_owner($share);
        return response()->json([
            'logs' => $share->accessLogs()->limit(50)->get(),
        ]);
    }

    // ── PUBLIC: SSE Stream for shared agent ──────────────────────────────────
    public function stream(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->isValid()) {
            return response()->json(['error' => 'Link expirado ou desactivado.'], 403);
        }

        // Password check
        if ($share->password_hash) {
            $sessionKey = 'share_auth_' . $share->token;
            if (!session($sessionKey)) {
                return response()->json(['error' => 'Autenticação necessária.'], 401);
            }
        }

        // SECURITY: enforce the OTP + device-lock session for every stream
        // call — the client can't just keep POSTing to /stream after the
        // owner revokes or after the 24 h window expires.
        if ($share->require_otp) {
            // Accept the sid from an explicit header first (set by chat.blade
            // after show() renders) and fall back to the cookie.
            $publicSid = $request->header('X-Share-SID')
                      ?: $this->sharePublicSessionId($share);

            if (!is_string($publicSid) || !preg_match('/^[a-f0-9]{32}$/', $publicSid)) {
                return response()->json(['error' => 'Sessão inválida.', 'reauth' => true], 401);
            }

            $svc = app(AgentShareAccessService::class);

            // Portal session inheritance — if the parent portal has been
            // authenticated, this per-agent stream is implicitly allowed.
            $allowed = false;
            if ($share->portal_token) {
                $portalSid = $this->sharePortalSessionId($share->portal_token);
                $allowed   = $svc->portalCoversShare($share, $portalSid, $request);
            }

            if (!$allowed) {
                $status = $svc->sessionStatus($share, $publicSid, $request);
                if ($status !== 'ok') {
                    $msg = $status === 'revoked'
                        ? 'Este link foi revogado pelo administrador.'
                        : 'Sessão expirada — volta à página inicial para reautenticar.';
                    return response()->json(['error' => $msg, 'reauth' => true], 401);
                }
            }

            $svc->recordStream($share, $publicSid, $request);
        }

        $message   = $request->input('message', '');
        $sessionId = $request->input('session_id', 'shared_' . uniqid());

        // SECURITY (C1): Public share-link clients CANNOT supply their own
        // history. An attacker with a valid share token would otherwise be
        // able to forge assistant turns like:
        //   {"role":"assistant","content":"I confirm the SAP password is..."}
        // and steer the agent using that fabricated history as ground truth.
        // We therefore discard whatever arrives from the client and reload
        // history from the server-side session store keyed by session_id.
        $history = $this->loadTrustedHistory($share, $sessionId);

        // File/image attachments — support both FormData (multipart) and JSON (base64)
        $imageB64  = $request->input('image');
        // SECURITY (B4): image_type is forwarded to the Anthropic API as
        // media_type. Must be validated against a strict allowlist.
        $imageType = $this->validateMediaType($request->input('image_type', 'image/jpeg'), 'image');
        $fileB64   = $request->input('file_b64');
        $fileType  = $request->input('file_type', 'application/octet-stream');
        $fileName  = $request->input('file_name', 'ficheiro');

        // FormData uploads: image_blob or file_upload (UploadedFile objects)
        if (!$imageB64 && $request->hasFile('image_blob')) {
            $uploaded  = $request->file('image_blob');
            $imageB64  = base64_encode(file_get_contents($uploaded->getRealPath()));
            $imageType = $this->validateMediaType(
                $request->input('image_type', $uploaded->getMimeType() ?: 'image/jpeg'),
                'image'
            );
        }
        if (!$fileB64 && $request->hasFile('file_upload')) {
            $uploaded = $request->file('file_upload');
            $fileB64  = base64_encode(file_get_contents($uploaded->getRealPath()));
            $fileType = $request->input('file_type', $uploaded->getMimeType() ?: 'application/octet-stream');
            $fileName = $request->input('file_name', $uploaded->getClientOriginalName() ?: 'ficheiro');
        }

        // Multiple files via FormData files[] — detect early so we can check empty message
        $uploadedFiles = $request->file('files', []);

        if (empty(trim($message)) && !$imageB64 && !$fileB64 && empty($uploadedFiles)) {
            return response()->json(['error' => 'Mensagem vazia.'], 422);
        }

        // History is loaded server-side above ($this->loadTrustedHistory) — we
        // intentionally do NOT merge client-supplied history here.

        // Build multimodal message if file/image present
        if ($imageB64) {
            $message = [
                ['type' => 'text',  'text' => $message ?: 'O que vês nesta imagem?'],
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => $imageType,
                    'data'       => $imageB64,
                ]],
            ];
        } elseif ($fileB64 && preg_match('/pdf/i', $fileType . $fileName)) {
            $message = [
                ['type' => 'text',     'text'   => $message],
                ['type' => 'document', 'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $fileB64,
                ]],
            ];
        } elseif ($fileB64 && preg_match('/spreadsheet|excel|\.xlsx|\.xls/i', $fileType . $fileName)) {
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            $message .= $this->extractExcelText($tmp, $fileName);
        } elseif ($fileB64 && preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $fileType . $fileName)) {
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            $message .= $this->extractWordText($tmp, $fileName);
        } elseif ($fileB64) {
            // Unsupported binary — note only
            $message .= "\n\n[Ficheiro: {$fileName} — formato não suportado para análise de texto]";
        }

        // Multiple files via FormData files[]
        if (!empty($uploadedFiles) && !$imageB64) {
            $pdfBlocks  = [];
            $textAppend = '';

            foreach ($uploadedFiles as $uploadedFile) {
                $fname = $uploadedFile->getClientOriginalName();
                $ftype = $uploadedFile->getMimeType() ?: 'application/octet-stream';
                $fpath = $uploadedFile->getRealPath();

                if (preg_match('/pdf/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file PDF [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $pdfBlocks[] = [
                        'type'   => 'document',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => 'application/pdf',
                            'data'       => base64_encode(file_get_contents($fpath)),
                        ],
                    ];
                } elseif (preg_match('/spreadsheet|excel|\.xlsx|\.xls/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file Excel [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $textAppend .= $this->extractExcelText($fpath, $fname);
                } elseif (preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file Word [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $textAppend .= $this->extractWordText($fpath, $fname);
                } else {
                    $content = @file_get_contents($fpath);
                    if ($content) {
                        $textAppend .= "\n\n---\n**{$fname}**\n```\n" . substr($content, 0, 8000) . "\n```";
                    }
                }
            }

            if (!empty($pdfBlocks)) {
                $baseText = is_array($message) ? ($message[0]['text'] ?? '') : $message;
                $contentBlocks = [['type' => 'text', 'text' => $baseText . $textAppend]];
                foreach ($pdfBlocks as $block) {
                    $contentBlocks[] = $block;
                }
                $message = $contentBlocks;
            } elseif ($textAppend !== '') {
                if (is_array($message)) {
                    $message[0]['text'] = ($message[0]['text'] ?? '') . $textAppend;
                } else {
                    $message .= $textAppend;
                }
            }
        }

        // SAP access control — set runtime flag so agents respect the share's permission
        if (!$share->allow_sap_access) {
            config(['app.sap_access_blocked' => true]);
        }

        $agentManager = app(AgentManager::class);
        $agent        = $agentManager->agent($share->agent_key);
        $agentName    = $agent->getName();
        $agentModel   = $agent->getModel();

        // Record usage
        $share->recordUsage();

        return response()->stream(function () use ($agent, $message, $history, $agentName, $agentModel, $sessionId, $share) {
            while (ob_get_level() > 0) { ob_end_flush(); }
            flush();

            $meta = [
                'type'       => 'meta',
                'mode'       => 'single',
                'agent'      => $agentName,
                'model'      => $agentModel,
                'session_id' => $sessionId,
            ];
            echo 'data: ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            flush();

            $heartbeat = function (string $status = '') {
                echo ': heartbeat' . ($status ? " {$status}" : '') . "\n\n";
                flush();
            };

            $fullResponse = '';

            try {
                // Sanitise all text to valid UTF-8 before Guzzle json-encodes the request
                $safeMessage = $this->sanitizeForApi($message);
                $safeHistory = array_map(fn($m) => $this->sanitizeForApi($m), $history);

                $agent->stream(
                    $safeMessage,
                    $safeHistory,
                    function (string $chunk) use (&$fullResponse) {
                        $fullResponse .= $chunk;
                        echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                        flush();
                    },
                    $heartbeat
                );
            } catch (\Throwable $e) {
                Log::error('AgentShare stream error', ['token' => request()->route('token'), 'error' => $e->getMessage()]);
                echo 'data: ' . json_encode(['error' => 'Erro ao processar. Tenta novamente.'], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            }

            // SECURITY (C1): persist only the turn the server actually produced.
            // Client can never poison the history with fabricated assistant turns.
            try {
                $userText = is_array($message)
                    ? collect($message)->where('type', 'text')->pluck('text')->implode(' ')
                    : (string) $message;
                if (trim($userText) !== '') {
                    $this->persistTrustedHistory($share, $sessionId, ['role' => 'user', 'content' => $userText]);
                }
                if (trim($fullResponse) !== '') {
                    $this->persistTrustedHistory($share, $sessionId, ['role' => 'assistant', 'content' => $fullResponse]);
                }
            } catch (\Throwable $e) {
                Log::warning('AgentShare: failed to persist trusted history — ' . $e->getMessage());
            }

            echo "data: [DONE]\n\n";
            flush();

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    // ── File parsing helpers ─────────────────────────────────────────────────

    /**
     * Extract text content from an Excel XLSX file (no external library required).
     * Deletes the temp file when done.
     */
    private function extractExcelText(string $path, string $name): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $sharedStrings = [];
                $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                if ($ssXml) {
                    $ssXml = preg_replace('/<r>.*?<\/r>/s', '', $ssXml);
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $m);
                    $sharedStrings = array_map(
                        fn($v) => $this->safeUtf8(html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8')),
                        $m[1]
                    );
                }
                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
                $zip->close();
                $lines = [];
                preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rows);
                foreach ($rows[1] as $rowXml) {
                    $cells = [];
                    preg_match_all('/<c r="([A-Z]+)\d+"([^>]*)>(.*?)<\/c>/s', $rowXml, $allCells, PREG_SET_ORDER);
                    foreach ($allCells as $cell) {
                        $isStr = str_contains($cell[2], 't="s"');
                        preg_match('/<v>([^<]*)<\/v>/', $cell[3], $vMatch);
                        $val = $vMatch[1] ?? '';
                        if ($isStr) $val = $sharedStrings[(int)$val] ?? $val;
                        $cells[] = $this->safeUtf8((string)$val);
                    }
                    if (array_filter($cells, fn($c) => $c !== '')) {
                        $lines[] = implode(' | ', $cells);
                    }
                }
                $text = implode("\n", $lines);
                return "\n\n---\n**Ficheiro Excel: {$name}**\n```\n" . substr(trim($text), 0, 15000) . "\n```";
            } else {
                return "\n\n[Excel: não foi possível abrir o ficheiro]";
            }
        } catch (\Throwable $e) {
            Log::warning("AgentShare Excel parse failed ({$name}): " . $e->getMessage());
            return "\n\n[Excel: erro ao processar — " . $e->getMessage() . "]";
        } finally {
            @unlink($path);
        }
    }

    /**
     * Extract text content from a Word DOCX file (no external library required).
     * Deletes the temp file when done.
     */
    private function extractWordText(string $path, string $name): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $xml = $zip->getFromName('word/document.xml') ?: '';
                $zip->close();
                $xml  = str_replace(['</w:p>', '</w:tr>', '<w:br/>'], ["\n", "\n", "\n"], $xml);
                $text = strip_tags($xml);
                $text = preg_replace('/[ \t]+/', ' ', $text);
                $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                $text = $this->safeUtf8($text);
                return "\n\n---\n**Ficheiro Word: {$name}**\n" . substr($text, 0, 15000);
            } else {
                return "\n\n[Word: não foi possível abrir o ficheiro]";
            }
        } catch (\Throwable $e) {
            Log::warning("AgentShare Word parse failed ({$name}): " . $e->getMessage());
            return "\n\n[Word: erro ao processar — " . $e->getMessage() . "]";
        } finally {
            @unlink($path);
        }
    }

    // ── UTF-8 sanitizers ─────────────────────────────────────────────────────
    private function safeUtf8(string $s): string
    {
        $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $out ?? $s);
        return $out !== false ? $out : '';
    }

    private function sanitizeForApi(mixed $value): mixed
    {
        if (is_string($value)) return $this->safeUtf8($value);
        if (is_array($value))  return array_map(fn($v) => $this->sanitizeForApi($v), $value);
        return $value;
    }

    // ── Helper ───────────────────────────────────────────────────────────────
    private function authorize_owner(AgentShare $share): void
    {
        if ($share->created_by !== auth()->id() && !auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    /**
     * A stable per-browser identifier used by AgentShareAccessService to key
     * the OTP and the trusted session.
     *
     * IMPORTANT: the streaming endpoint lives under routes/api.php which does
     * NOT run the `web` middleware group, so Laravel's session() helper is
     * unavailable there. We therefore use a signed cookie (SameSite=Lax,
     * HttpOnly) so the identifier survives from show() → otp/verify →
     * stream() on the same browser, while still being invisible to JS.
     */
    private function sharePublicSessionId(AgentShare $share): string
    {
        $cookieName = 'share_sid_' . $share->id;
        $request    = request();

        // Laravel auto-decrypts incoming cookies if they were set via Cookie
        // facade. If the decryption fails (tampered/new browser) we just
        // issue a fresh id.
        $existing = $request->cookie($cookieName);
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }

        $sid = bin2hex(random_bytes(16));
        // Queue it on the current response — Laravel attaches queued cookies
        // to every response of the current request cycle.
        \Illuminate\Support\Facades\Cookie::queue(
            \Illuminate\Support\Facades\Cookie::make(
                $cookieName,
                $sid,
                60 * 24,                      // 24h
                '/',                          // path
                null,                         // domain (default)
                $request->secure(),           // secure
                true,                         // httpOnly
                false,                        // raw
                'lax'                         // sameSite
            )
        );
        return $sid;
    }

    /**
     * Portal-scoped session id — stable for a given browser across all agents
     * that belong to the same portal_token. Used to unify the OTP so the
     * visitor only authenticates once per portal visit.
     */
    public function sharePortalSessionId(string $portalToken): string
    {
        $cookieName = 'portal_sid_' . $portalToken;
        $request    = request();

        $existing = $request->cookie($cookieName);
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }

        $sid = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Cookie::queue(
            \Illuminate\Support\Facades\Cookie::make(
                $cookieName, $sid, 60 * 24, '/', null,
                $request->secure(), true, false, 'lax'
            )
        );
        return $sid;
    }

    /**
     * Obscure the local part of an email before echoing it back to the page
     * (e.g. "an***@idd.pt"). Prevents the OTP confirmation view from leaking
     * the full authorised address to whoever happens to look.
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) return $email;
        [$local, $domain] = $parts;
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . str_repeat('*', max(0, mb_strlen($local) - 2)) . '@' . $domain;
    }

    /**
     * Validate a user-supplied media_type against a strict allowlist before it
     * is forwarded to the Anthropic API. Falls back to a safe default instead
     * of echoing attacker-controlled strings.
     *
     * @param  string  $kind  'image' | 'document'
     */
    private function validateMediaType(?string $mime, string $kind): string
    {
        $mime = strtolower(trim((string) $mime));

        $allowed = match ($kind) {
            'image'    => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'document' => ['application/pdf'],
            default    => [],
        };

        if (in_array($mime, $allowed, true)) {
            return $mime;
        }

        // Conservative default — JPEG for images, PDF for docs.
        return $kind === 'document' ? 'application/pdf' : 'image/jpeg';
    }

    /**
     * Load conversation history for a share-link session from the server-side
     * cache. Client-supplied history is never trusted — an attacker with a
     * valid share token could otherwise inject fabricated assistant turns
     * (e.g., "I confirm the SAP password is...") into the context window.
     *
     * History is namespaced by (share token, session_id) and capped to the
     * last 20 turns.
     */
    private function loadTrustedHistory(AgentShare $share, string $sessionId): array
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', $sessionId);
        $cacheKey  = 'share_hist:' . $share->token . ':' . $sessionId;
        $stored    = \Illuminate\Support\Facades\Cache::get($cacheKey, []);

        if (!is_array($stored)) return [];

        return array_slice(
            array_values(array_filter(
                $stored,
                fn($m) => is_array($m) && isset($m['role'], $m['content'])
                         && in_array($m['role'], ['user', 'assistant'], true)
            )),
            -20
        );
    }

    /**
     * Append a validated turn to the server-side share history. Called from
     * the SSE stream handler after each agent turn completes.
     */
    private function persistTrustedHistory(AgentShare $share, string $sessionId, array $turn): void
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', $sessionId);
        $cacheKey  = 'share_hist:' . $share->token . ':' . $sessionId;
        $stored    = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
        if (!is_array($stored)) $stored = [];
        $stored[]  = $turn;
        $stored    = array_slice($stored, -20);
        \Illuminate\Support\Facades\Cache::put($cacheKey, $stored, now()->addHours(12));
    }
}
