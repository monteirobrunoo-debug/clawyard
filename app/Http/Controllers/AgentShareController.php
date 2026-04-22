<?php

namespace App\Http\Controllers;

use App\Models\AgentShare;
use App\Agents\AgentManager;
use App\Services\AgentCatalog;
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
        // SECURITY: client_email is REQUIRED (primary recipient). additional_emails
        // is optional and accepts a list (array OR comma/newline-separated string
        // coming from the admin form). All recipients in this union are valid
        // for OTP — see AgentShare::authorisedEmails().
        $data = $request->validate([
            'agent_key'         => 'required|string|max:50',
            'client_name'       => 'required|string|max:100',
            'client_email'      => 'required|email|max:150',
            // Accept either a raw textarea string ("a@x.pt, b@x.pt\nc@x.pt")
            // or an array of emails submitted as additional_emails[].
            'additional_emails' => 'nullable',
            'password'          => 'nullable|string|min:4|max:100',
            'custom_title'      => 'nullable|string|max:100',
            'welcome_message'   => 'nullable|string|max:500',
            'show_branding'     => 'nullable|boolean',
            'allow_sap_access'  => 'nullable|boolean',
            'require_otp'       => 'nullable|boolean',
            'lock_to_device'    => 'nullable|boolean',
            'notify_on_access'  => 'nullable|boolean',
            'notify_email'      => 'nullable|email|max:150',
            'notify_whatsapp'   => 'nullable|string|max:30',
            'expires_at'        => 'nullable|date|after:now',
            // When the client is creating N shares in one batch, it passes
            // the same portal_token so the backend can group them into a
            // single /p/{portal_token} landing page.
            'portal_token'      => 'nullable|string|size:24|alpha_num',
        ]);

        $primaryEmail     = strtolower(trim($data['client_email']));
        $additionalEmails = $this->parseAdditionalEmails($data['additional_emails'] ?? null, $primaryEmail);

        $share = AgentShare::create([
            'token'             => AgentShare::generateToken(),
            'portal_token'      => $data['portal_token'] ?? null,
            'agent_key'         => $data['agent_key'],
            'client_name'       => $data['client_name'],
            'client_email'      => $primaryEmail,
            'additional_emails' => !empty($additionalEmails) ? $additionalEmails : null,
            'password_hash'     => !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
            'custom_title'      => $data['custom_title'] ?? null,
            'welcome_message'   => $data['welcome_message'] ?? null,
            'show_branding'     => $request->boolean('show_branding', true),
            'allow_sap_access'  => $request->boolean('allow_sap_access', false),
            'require_otp'       => $request->boolean('require_otp', true),
            'lock_to_device'    => $request->boolean('lock_to_device', true),
            'notify_on_access'  => $request->boolean('notify_on_access', true),
            'notify_email'      => $data['notify_email'] ?? auth()->user()->email,
            'notify_whatsapp'   => $data['notify_whatsapp'] ?? null,
            'expires_at'        => $data['expires_at'] ?? now()->addHours(24),
            'created_by'        => auth()->id(),
        ]);

        // Notify every recipient with the share link. Each recipient gets their
        // own email (separate TO header) so the thread looks personalised.
        //
        // BATCH MODE: when the caller is creating N shares under the same
        // portal_token it passes `skip_email=true` on every create and then
        // calls `/admin/shares/portal-email` once at the end. That sends a
        // SINGLE bundled email per recipient listing every agent (with
        // original photos/avatars) instead of N separate emails.
        $emailsSent = 0;
        if (!$request->boolean('skip_email', false)) {
            foreach ($share->authorisedEmails() as $recipient) {
                if ($this->sendShareEmail($share, $data['password'] ?? null, $recipient)) {
                    $emailsSent++;
                }
            }
        }

        return response()->json([
            'ok'                 => true,
            'url'                => $share->getUrl(),
            'portal_url'         => $share->getPortalUrl(),
            'id'                 => $share->id,
            'email_sent'         => $emailsSent > 0,
            'emails_sent_count'  => $emailsSent,
            'email_skipped'      => $request->boolean('skip_email', false),
            'recipients'         => $share->authorisedEmails(),
        ]);
    }

    /**
     * Normalise the "additional emails" input the admin submits. Accepts:
     *   - an array already ["a@x.pt", "b@x.pt"]
     *   - a textarea string with comma / newline / semicolon separators
     * Returns a deduped, lowercased array EXCLUDING the primary email (we
     * never list the primary twice) and capped to 20 to prevent abuse.
     */
    private function parseAdditionalEmails(mixed $raw, string $primaryEmail): array
    {
        if (empty($raw)) return [];
        $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $out  = [];
        foreach ($list as $e) {
            $e = strtolower(trim((string) $e));
            if ($e === '' || $e === $primaryEmail) continue;
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            $out[] = $e;
        }
        return array_slice(array_values(array_unique($out)), 0, 20);
    }

    /**
     * Send the share link via email. Uses MAIL_FROM_ADDRESS (no-reply@…) as
     * the From header so recipients see a consistent HP-Group sender.
     *
     * $recipientEmail — if provided, the message is delivered to THAT address
     * (enables multi-recipient shares: one email per authorised recipient,
     * separate TO headers so the thread looks personalised). If null we fall
     * back to $share->client_email for backward compatibility.
     *
     * Returns true on success, false on any Mail failure.
     */
    private function sendShareEmail(AgentShare $share, ?string $password, ?string $recipientEmail = null): bool
    {
        try {
            // Resolve who this email is actually going to. We only deliver to
            // an address that is part of the authorised set to defend against
            // a caller passing an arbitrary email.
            $recipientEmail = $recipientEmail
                ? strtolower(trim($recipientEmail))
                : strtolower(trim((string) $share->client_email));

            if ($recipientEmail !== '' && !$share->isAuthorisedEmail($recipientEmail)) {
                Log::warning('AgentShare: refused to email non-authorised recipient', [
                    'share_id'  => $share->id,
                    'recipient' => $recipientEmail,
                ]);
                return false;
            }
            $meta     = AgentShare::agentMeta()[$share->agent_key] ?? [
                'name'  => ucfirst($share->agent_key),
                'emoji' => '🤖',
                'color' => '#76b900',
                'role'  => 'Agente ClawYard',
            ];

            $agentName  = (string)($meta['name']  ?? ucfirst($share->agent_key));
            $agentEmoji = (string)($meta['emoji'] ?? '🤖');
            $agentColor = (string)($meta['color'] ?? '#76b900');
            $agentRole  = trim((string)($meta['role'] ?? ''));
            $agentLbl   = trim($agentEmoji . ' ' . $agentName);

            // Top-3 starter prompts ("O que podes pedir") so the recipient
            // immediately understands the practical value of this agent.
            $starters = array_slice(AgentCatalog::starters($share->agent_key), 0, 3);

            $url      = $share->getUrl();
            $expires  = $share->expires_at?->format('d/m/Y \à\s H:i') ?? '— sem expiração —';
            $ownerNm  = auth()->user()?->name  ?? 'Equipa HP-Group';
            $ownerEm  = auth()->user()?->email ?? config('mail.from.address', 'no-reply@hp-group.org');

            // ── Escaped variables (all user-controlled content) ────────────
            $clientName     = htmlspecialchars($share->client_name);
            $agentNameEsc   = htmlspecialchars($agentName);
            $agentEmojiEsc  = htmlspecialchars($agentEmoji);
            $agentLblEsc    = htmlspecialchars($agentLbl);
            $agentRoleEsc   = htmlspecialchars($agentRole);
            $agentColorEsc  = htmlspecialchars($agentColor);
            $ownerNmEsc     = htmlspecialchars($ownerNm);
            $ownerEmEsc     = htmlspecialchars($ownerEm);
            $urlEsc         = htmlspecialchars($url);
            $expiresEsc     = htmlspecialchars($expires);
            $clientEmailEsc = htmlspecialchars((string) $share->client_email);

            // ── Agent card (big visual header with name + role) ────────────
            $agentCard = <<<HTMLCARD
<div style="background:linear-gradient(135deg,{$agentColorEsc}18,{$agentColorEsc}08);border:1px solid {$agentColorEsc}40;border-radius:10px;padding:20px 22px;margin:22px 0;">
  <div style="display:flex;align-items:center;gap:14px;">
    <div style="width:56px;height:56px;border-radius:12px;background:{$agentColorEsc}25;display:inline-flex;align-items:center;justify-content:center;font-size:30px;line-height:1;flex-shrink:0;">{$agentEmojiEsc}</div>
    <div>
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#4d7a00;font-weight:700;margin-bottom:2px;">Agente atribuído</div>
      <div style="font-size:19px;font-weight:800;color:#111;line-height:1.25;">{$agentNameEsc}</div>
    </div>
  </div>
HTMLCARD;

            if ($agentRole !== '') {
                $agentCard .= '<div style="font-size:13px;color:#333;margin-top:14px;padding-top:14px;border-top:1px solid ' . $agentColorEsc . '30;line-height:1.55;">'
                    . '<strong style="color:#111;">O que faz este agente:</strong> '
                    . $agentRoleEsc
                    . '</div>';
            }

            // "O que podes pedir" — list of 3 sample prompts
            if (!empty($starters)) {
                $agentCard .= '<div style="margin-top:14px;">';
                $agentCard .= '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#4d7a00;font-weight:700;margin-bottom:8px;">Exemplos do que podes pedir</div>';
                foreach ($starters as $starter) {
                    $agentCard .= '<div style="font-size:13px;color:#222;background:#fff;border:1px solid ' . $agentColorEsc . '25;border-radius:6px;padding:8px 12px;margin-bottom:6px;">'
                        . '💬 ' . htmlspecialchars((string) $starter)
                        . '</div>';
                }
                $agentCard .= '</div>';
            }
            $agentCard .= '</div>';

            // ── Access instructions (how to actually get in) ───────────────
            $accessSteps = '<ol style="margin:8px 0 0 0;padding-left:20px;color:#374151;">'
                . '<li style="margin-bottom:6px;">Clica no botão <strong>&quot;Abrir agente&quot;</strong> abaixo (ou copia o link).</li>';

            if ($share->require_otp) {
                $accessSteps .= '<li style="margin-bottom:6px;">Insere o teu email (<strong>' . $clientEmailEsc . '</strong>) — vais receber um <strong>código de 6 dígitos</strong> no mesmo momento.</li>'
                    . '<li style="margin-bottom:6px;">Cola o código para entrar. A sessão fica válida 24&nbsp;horas neste browser.</li>';
            } else {
                $accessSteps .= '<li style="margin-bottom:6px;">Entras directamente na conversa com o agente.</li>';
            }

            if ($password) {
                $accessSteps .= '<li style="margin-bottom:6px;">Será pedida a <strong>palavra-passe</strong> indicada em baixo.</li>';
            }
            $accessSteps .= '</ol>';

            $passwordBlock = '';
            if ($password) {
                $passwordBlock = '<div style="margin:16px 0;padding:14px 18px;background:#fff7ed;border-left:3px solid #f59e0b;border-radius:4px;">'
                    . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#92400e;font-weight:700;margin-bottom:4px;">Palavra-passe</div>'
                    . '<div style="font-family:Menlo,Consolas,monospace;font-size:16px;color:#111;font-weight:700;">' . htmlspecialchars($password) . '</div>'
                    . '<div style="font-size:11px;color:#92400e;margin-top:6px;">Guarda esta palavra-passe — não a repetirei noutro email.</div>'
                    . '</div>';
            }

            $welcome = $share->welcome_message
                ? '<div style="margin:16px 0;padding:14px 18px;background:#f0f9ff;border-left:3px solid #0ea5e9;border-radius:4px;">'
                    . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#0369a1;font-weight:700;margin-bottom:4px;">Mensagem de boas-vindas</div>'
                    . '<div style="font-size:14px;color:#0c4a6e;">' . nl2br(htmlspecialchars($share->welcome_message)) . '</div>'
                    . '</div>'
                : '';

            // Security flags shown at the bottom so the recipient understands
            // the protections in place (OTP, device-lock, expiry).
            $securityFlags = [];
            if ($share->require_otp)   $securityFlags[] = '🔐 Autenticação por código email (OTP)';
            if ($share->lock_to_device) $securityFlags[] = '📱 Sessão fixada a este dispositivo/browser';
            if ($share->expires_at)     $securityFlags[] = '⏱ Expira automaticamente a ' . htmlspecialchars($share->expires_at->format('d/m/Y H:i'));
            if ($password)              $securityFlags[] = '🔑 Protegido por palavra-passe adicional';
            $securityBlock = '';
            if (!empty($securityFlags)) {
                $securityBlock = '<div style="margin-top:20px;padding:14px 18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">'
                    . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;font-weight:700;margin-bottom:8px;">Segurança deste link</div>'
                    . '<div style="font-size:13px;color:#374151;line-height:1.8;">'
                    . implode('<br>', $securityFlags)
                    . '</div></div>';
            }

            $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222; line-height: 1.65; background: #f4f4f4; margin: 0; padding: 0; }
  .wrap { background: #fff; max-width: 640px; margin: 24px auto; padding: 32px; border-radius: 8px; }
  .hdr { border-bottom: 3px solid #76b900; padding-bottom: 14px; margin-bottom: 20px; display:flex;align-items:center;justify-content:space-between; }
  .logo { font-size: 22px; font-weight: bold; color: #76b900; }
  .tagline { font-size:11px;color:#6b7280;margin-top:2px; }
  .btn { display: inline-block; background: #76b900; color: #fff !important; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 700; margin: 8px 0; font-size:15px; }
  .meta { font-size: 13px; color: #555; }
  .footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
  .link-box { background:#f9fafb;border:1px dashed #d1d5db;border-radius:6px;padding:10px 14px;font-family:Menlo,Consolas,monospace;font-size:12px;color:#374151;word-break:break-all; }
  .section-title { font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;font-weight:700;margin:24px 0 8px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div>
      <div class="logo">🐾 ClawYard</div>
      <div class="tagline">PartYard · Setq.AI — Maritime Intelligence Platform</div>
    </div>
  </div>

  <p style="font-size:15px;">Olá <strong>{$clientName}</strong>,</p>

  <p>O <strong>{$ownerNmEsc}</strong> partilhou contigo um agente na plataforma <strong>ClawYard</strong>.
     Este email contém toda a informação necessária para entrares na conversa.</p>

  {$agentCard}

  {$welcome}

  <div class="section-title">Como entrar</div>
  {$accessSteps}

  <p style="text-align:center;margin:22px 0 8px;">
    <a href="{$urlEsc}" class="btn">▶ Abrir agente {$agentLblEsc}</a>
  </p>

  <div class="section-title">Link directo</div>
  <div class="link-box"><a href="{$urlEsc}" style="color:#374151;text-decoration:none;">{$urlEsc}</a></div>

  {$passwordBlock}

  {$securityBlock}

  <table class="meta" cellpadding="0" cellspacing="0" style="margin-top:22px;width:100%;">
    <tr><td style="padding:4px 12px 4px 0;width:130px;"><strong>Enviado para:</strong></td><td>{$clientEmailEsc}</td></tr>
    <tr><td style="padding:4px 12px 4px 0;"><strong>Válido até:</strong></td><td>{$expiresEsc}</td></tr>
    <tr><td style="padding:4px 12px 4px 0;"><strong>Partilhado por:</strong></td><td>{$ownerNmEsc} &lt;{$ownerEmEsc}&gt;</td></tr>
  </table>

  <p style="margin-top:20px;font-size:12px;color:#666;">
    Se não estavas à espera deste acesso, podes simplesmente ignorar o email — o link expira automaticamente na data acima
    e nenhum código será gerado sem alguém o pedir.
  </p>

  <div class="footer">
    <strong>ClawYard</strong> | IT Partyard LDA<br>
    Marine Spare Parts &amp; Technical Services<br>
    Setúbal, Portugal · <a href="mailto:no-reply@hp-group.org" style="color:#888;">no-reply@hp-group.org</a><br>
    <span style="color:#aaa;font-size:11px;">Este email foi enviado automaticamente pelo sistema — não respondas directamente.</span>
  </div>
</div>
</body>
</html>
HTML;

            // Plain-text fallback for clients that block HTML. Contains the
            // same essential information (agent name, role, link, OTP steps)
            // so the recipient is never locked out.
            $textLines = [
                'Olá ' . $share->client_name . ',',
                '',
                $ownerNm . ' partilhou contigo um agente na plataforma ClawYard.',
                '',
                '── AGENTE ──',
                $agentName . ' ' . $agentEmoji,
            ];
            if ($agentRole !== '') {
                $textLines[] = 'O que faz: ' . $agentRole;
            }
            if (!empty($starters)) {
                $textLines[] = '';
                $textLines[] = 'Exemplos do que podes pedir:';
                foreach ($starters as $s) $textLines[] = '  • ' . $s;
            }
            $textLines[] = '';
            $textLines[] = '── COMO ENTRAR ──';
            $textLines[] = '1. Abre: ' . $url;
            if ($share->require_otp) {
                // Tell the recipient to use THEIR own email (the one this
                // message was delivered to). For single-recipient shares this
                // is the same as client_email; for multi-recipient shares
                // each recipient sees their own address here.
                $textLines[] = '2. Insere o teu email (' . $recipientEmail . ')';
                $textLines[] = '3. Recebes um código de 6 dígitos — cola-o para entrar.';
            }
            if ($password) {
                $textLines[] = 'Palavra-passe adicional: ' . $password;
            }
            $textLines[] = '';
            $textLines[] = 'Válido até: ' . $expires;
            $textLines[] = 'Partilhado por: ' . $ownerNm . ' <' . $ownerEm . '>';
            $textLines[] = '';
            $textLines[] = 'Se não estavas à espera deste acesso, ignora o email — o link expira.';
            $textLines[] = '';
            $textLines[] = 'ClawYard | IT Partyard LDA · Setúbal, Portugal';
            $textLines[] = 'no-reply@hp-group.org (não respondas a este email)';
            $plain = implode("\n", $textLines);

            // Explicit no-reply sender — overrides whatever authn identity is
            // on the current request, so the recipient always sees
            // "HP-Group / ClawYard <no-reply@hp-group.org>".
            $fromAddress = config('mail.from.address', 'no-reply@hp-group.org');
            $fromName    = config('mail.from.name',    'HP-Group / ClawYard');
            $replyTo     = config('mail.reply_to.address') ?: $fromAddress;

            // Use Mail::send so we can attach BOTH HTML and plain-text bodies
            // (html() alone would leave us with HTML-only, which some strict
            // corporate filters downgrade to "empty" and quarantine).
            Mail::send([], [], function ($mail) use ($share, $agentLbl, $recipientEmail, $fromAddress, $fromName, $replyTo, $html, $plain) {
                $mail->to($recipientEmail, $share->client_name)
                     ->from($fromAddress, $fromName)
                     ->replyTo($replyTo, $fromName)
                     ->subject('[ClawYard] Acesso ao agente ' . $agentLbl);

                // Reach through to the underlying Symfony Email so we can
                // set both parts without creating a dedicated Mailable class.
                $symfony = $mail->getSymfonyMessage();
                $symfony->html($html, 'utf-8');
                $symfony->text($plain, 'utf-8');
            });

            Log::info('AgentShare email sent', [
                'share_id'   => $share->id,
                'to'         => $recipientEmail,
                'agent_key'  => $share->agent_key,
                'from'       => $fromAddress,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Rich error log so Forge → Logs shows the actual cause (SMTP
            // auth failure, relay denied, TLS mismatch, DNS, etc.) instead
            // of a generic "failed". We log at error (not warning) because
            // the recipient silently receives nothing and that's a real
            // incident, not a soft edge case.
            Log::error('AgentShare email failed', [
                'share_id'    => $share->id ?? null,
                'to'          => $recipientEmail ?? ($share->client_email ?? null),
                'agent_key'   => $share->agent_key ?? null,
                'mailer'      => config('mail.default'),
                'smtp_host'   => config('mail.mailers.smtp.host'),
                'smtp_port'   => config('mail.mailers.smtp.port'),
                'error_class' => get_class($e),
                'error'       => $e->getMessage(),
                'file'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            return false;
        }
    }

    // ── ADMIN: Send the bundled portal email ─────────────────────────────────
    //
    // Called once after a batch of shares has been created with a shared
    // `portal_token` (and `skip_email=true` so no per-share emails went out).
    // Sends ONE email per recipient listing every agent in the portal with
    // their original photos/avatars and a single landing URL.
    public function sendPortalEmail(Request $request)
    {
        $data = $request->validate([
            'portal_token' => 'required|string|size:24|alpha_num',
            'password'     => 'nullable|string|max:100',
        ]);

        $shares = AgentShare::where('portal_token', $data['portal_token'])
            ->where('created_by', auth()->id())
            ->orderBy('id')
            ->get();

        if ($shares->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Portal não encontrado.'], 404);
        }

        // Union of every authorised recipient across every share in the bundle.
        // One person might be on every share; others might be on a subset. We
        // still send one email per unique address.
        $recipients = [];
        foreach ($shares as $s) {
            foreach ($s->authorisedEmails() as $e) {
                $recipients[$e] = true;
            }
        }
        $recipients = array_keys($recipients);

        $sent = 0;
        $failed = [];
        foreach ($recipients as $recipient) {
            if ($this->sendPortalBundleEmail($shares, $recipient, $data['password'] ?? null)) {
                $sent++;
            } else {
                $failed[] = $recipient;
            }
        }

        return response()->json([
            'ok'               => true,
            'recipients'       => $recipients,
            'emails_sent'      => $sent,
            'emails_failed'    => $failed,
            'agents_in_portal' => $shares->count(),
        ]);
    }

    /**
     * Render and deliver the "one email, every agent" portal bundle.
     *
     * Each agent row includes:
     *   - the agent's original photo (from AgentShare::agentMeta, resolved to
     *     an absolute URL so remote mail clients can fetch it)
     *   - name + role + per-agent direct link (for fallback if the portal
     *     URL is blocked by the recipient's firewall)
     * The primary CTA is the portal URL — one link that unlocks all agents
     * with a single OTP.
     */
    private function sendPortalBundleEmail($shares, string $recipientEmail, ?string $password): bool
    {
        try {
            $first = $shares->first();
            if (!$first || !$first->portal_token) return false;

            // Filter the bundle to shares this recipient is actually authorised
            // for. Someone added only to 2 of 5 shares sees just those 2 cards.
            $visibleShares = $shares->filter(fn($s) => $s->isAuthorisedEmail($recipientEmail))->values();
            if ($visibleShares->isEmpty()) return false;

            $meta      = AgentShare::agentMeta();
            $portalUrl = $first->getPortalUrl();
            $clientNm  = htmlspecialchars($first->client_name);
            $ownerNm   = auth()->user()?->name  ?? 'Equipa HP-Group';
            $ownerEm   = auth()->user()?->email ?? config('mail.from.address', 'no-reply@hp-group.org');
            $ownerNmEs = htmlspecialchars($ownerNm);
            $ownerEmEs = htmlspecialchars($ownerEm);
            $portalUrlEs = htmlspecialchars($portalUrl);
            $expires   = $first->expires_at?->format('d/m/Y \à\s H:i') ?? '— sem expiração —';
            $expiresEs = htmlspecialchars($expires);
            // Public base URL for the avatar images. Most mail clients will
            // fetch these over HTTPS after the user clicks "show images".
            $assetBase = rtrim(config('app.share_url', config('app.url')), '/');

            // ── Build one card per agent ────────────────────────────────────
            $agentCards = '';
            foreach ($visibleShares as $s) {
                $m          = $meta[$s->agent_key] ?? ['name' => ucfirst($s->agent_key), 'emoji' => '🤖', 'color' => '#76b900', 'photo' => null, 'role' => 'Agente ClawYard'];
                $agentName  = (string) ($m['name']  ?? ucfirst($s->agent_key));
                $agentEmoji = (string) ($m['emoji'] ?? '🤖');
                $agentColor = (string) ($m['color'] ?? '#76b900');
                $agentRole  = trim((string) ($m['role']  ?? ''));
                $photoPath  = $m['photo'] ?? null;

                $photoUrl   = $photoPath ? $assetBase . $photoPath : null;

                $agentNameEs  = htmlspecialchars($agentName);
                $agentRoleEs  = htmlspecialchars($agentRole);
                $agentColorEs = htmlspecialchars($agentColor);
                $agentEmojiEs = htmlspecialchars($agentEmoji);
                $agentUrlEs   = htmlspecialchars($s->getUrl());

                // Avatar — real photo if available (with emoji fallback as
                // alt text + background colour so blocked images still look
                // good), else a coloured circle + emoji.
                if ($photoUrl) {
                    $avatar = '<img src="' . htmlspecialchars($photoUrl) . '" alt="' . $agentEmojiEs . '" width="56" height="56" '
                            . 'style="width:56px;height:56px;border-radius:50%;object-fit:cover;display:block;border:2px solid ' . $agentColorEs . '40;background:' . $agentColorEs . '20;">';
                } else {
                    $avatar = '<div style="width:56px;height:56px;border-radius:50%;background:' . $agentColorEs . '25;display:inline-flex;align-items:center;justify-content:center;font-size:28px;line-height:1;">' . $agentEmojiEs . '</div>';
                }

                $agentCards .= <<<HTMLCARD
<tr>
  <td style="padding:0 0 12px 0;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#fff;border:1px solid {$agentColorEs}30;border-left:4px solid {$agentColorEs};border-radius:10px;">
      <tr>
        <td width="72" style="padding:14px 0 14px 16px;vertical-align:top;">{$avatar}</td>
        <td style="padding:14px 16px;vertical-align:top;">
          <div style="font-size:15px;font-weight:700;color:#111;line-height:1.2;margin-bottom:3px;">{$agentNameEs}</div>
          <div style="font-size:12px;color:#4b5563;line-height:1.55;">{$agentRoleEs}</div>
          <div style="margin-top:8px;">
            <a href="{$agentUrlEs}" style="color:{$agentColorEs};text-decoration:none;font-size:12px;font-weight:600;">▶ Abrir {$agentNameEs}</a>
          </div>
        </td>
      </tr>
    </table>
  </td>
</tr>
HTMLCARD;
            }

            $passwordBlock = '';
            if ($password) {
                $passwordBlock = '<div style="margin:16px 0;padding:14px 18px;background:#fff7ed;border-left:3px solid #f59e0b;border-radius:4px;">'
                    . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#92400e;font-weight:700;margin-bottom:4px;">Palavra-passe</div>'
                    . '<div style="font-family:Menlo,Consolas,monospace;font-size:16px;color:#111;font-weight:700;">' . htmlspecialchars($password) . '</div>'
                    . '<div style="font-size:11px;color:#92400e;margin-top:6px;">Guarda esta palavra-passe — não a repetirei noutro email.</div>'
                    . '</div>';
            }

            $agentCount = $visibleShares->count();
            $agentNoun  = $agentCount === 1 ? 'agente' : 'agentes';

            $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222; line-height: 1.6; background: #f4f4f4; margin:0; padding:0; }
  .wrap { background: #fff; max-width: 640px; margin: 24px auto; padding: 32px; border-radius: 8px; }
  .hdr { border-bottom: 3px solid #76b900; padding-bottom: 14px; margin-bottom: 20px; }
  .logo { font-size: 22px; font-weight: bold; color: #76b900; }
  .tagline { font-size: 11px; color: #6b7280; margin-top: 2px; }
  .btn { display: inline-block; background: #76b900; color: #fff !important; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; }
  .footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
  .section-title { font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;font-weight:700;margin:22px 0 10px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div class="logo">🐾 ClawYard</div>
    <div class="tagline">PartYard · Setq.AI — Maritime Intelligence Platform</div>
  </div>

  <p style="font-size:15px;">Olá <strong>{$clientNm}</strong>,</p>

  <p>O <strong>{$ownerNmEs}</strong> preparou para ti um portal com <strong>{$agentCount} {$agentNoun}</strong> na plataforma <strong>ClawYard</strong>.
     Um único link, uma única verificação — acedes a todos os agentes que precisares.</p>

  <p style="text-align:center;margin:22px 0 8px;">
    <a href="{$portalUrlEs}" class="btn">▶ Abrir o meu portal</a>
  </p>

  <div style="text-align:center;font-size:11px;color:#888;margin-bottom:20px;word-break:break-all;">
    ou cola este endereço: <a href="{$portalUrlEs}" style="color:#76b900;">{$portalUrlEs}</a>
  </div>

  <div class="section-title">Agentes disponíveis neste portal</div>
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
    {$agentCards}
  </table>

  {$passwordBlock}

  <div class="section-title">Como entrar</div>
  <ol style="margin:8px 0 0 18px;padding:0;color:#374151;font-size:13px;line-height:1.8;">
    <li>Clica em <strong>&quot;Abrir o meu portal&quot;</strong> acima.</li>
    <li>Introduz o teu email — recebes um <strong>código de 6 dígitos</strong>.</li>
    <li>Cola o código para entrar. A sessão fica válida 24 h neste browser e cobre TODOS os agentes do portal.</li>
  </ol>

  <p style="margin-top:18px;font-size:12px;color:#6b7280;">
    <strong>Válido até:</strong> {$expiresEs} &middot;
    <strong>Partilhado por:</strong> {$ownerNmEs} &lt;{$ownerEmEs}&gt;
  </p>

  <p style="margin-top:12px;font-size:12px;color:#6b7280;">
    Se não estavas à espera deste acesso, ignora o email — o portal expira automaticamente na data acima.
  </p>

  <div class="footer">
    <strong>ClawYard</strong> | IT Partyard LDA<br>
    Marine Spare Parts &amp; Technical Services<br>
    Setúbal, Portugal · <a href="mailto:no-reply@hp-group.org" style="color:#888;">no-reply@hp-group.org</a><br>
    <span style="color:#aaa;font-size:11px;">Este email foi enviado automaticamente — não respondas directamente.</span>
  </div>
</div>
</body>
</html>
HTML;

            // Plain-text fallback: same structure, no images.
            $lines = [
                'Olá ' . $first->client_name . ',',
                '',
                $ownerNm . ' preparou um portal com ' . $agentCount . ' ' . $agentNoun . ' na plataforma ClawYard.',
                '',
                'PORTAL: ' . $portalUrl,
                '',
                'AGENTES INCLUÍDOS:',
            ];
            foreach ($visibleShares as $s) {
                $m = $meta[$s->agent_key] ?? ['name' => ucfirst($s->agent_key), 'emoji' => '🤖', 'role' => ''];
                $lines[] = '  ' . ($m['emoji'] ?? '🤖') . ' ' . ($m['name'] ?? $s->agent_key)
                         . (!empty($m['role']) ? ' — ' . $m['role'] : '');
                $lines[] = '     ' . $s->getUrl();
            }
            if ($password) {
                $lines[] = '';
                $lines[] = 'Palavra-passe: ' . $password;
            }
            $lines[] = '';
            $lines[] = 'COMO ENTRAR:';
            $lines[] = '  1. Abre o link do portal.';
            $lines[] = '  2. Introduz o teu email — recebes um código de 6 dígitos.';
            $lines[] = '  3. Uma verificação só — cobre todos os agentes por 24 h.';
            $lines[] = '';
            $lines[] = 'Válido até: ' . $expires;
            $lines[] = 'Partilhado por: ' . $ownerNm . ' <' . $ownerEm . '>';
            $lines[] = '';
            $lines[] = 'ClawYard | IT Partyard LDA · Setúbal, Portugal';
            $plain = implode("\n", $lines);

            $fromAddress = config('mail.from.address', 'no-reply@hp-group.org');
            $fromName    = config('mail.from.name',    'HP-Group / ClawYard');
            $replyTo     = config('mail.reply_to.address') ?: $fromAddress;
            $subject     = '[ClawYard] O teu portal com ' . $agentCount . ' ' . $agentNoun;

            Mail::send([], [], function ($mail) use ($recipientEmail, $first, $fromAddress, $fromName, $replyTo, $subject, $html, $plain) {
                $mail->to($recipientEmail, $first->client_name)
                     ->from($fromAddress, $fromName)
                     ->replyTo($replyTo, $fromName)
                     ->subject($subject);
                $symfony = $mail->getSymfonyMessage();
                $symfony->html($html, 'utf-8');
                $symfony->text($plain, 'utf-8');
            });

            Log::info('AgentShare portal email sent', [
                'portal_token' => $first->portal_token,
                'to'           => $recipientEmail,
                'agent_count'  => $agentCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Same rich diagnostics as sendShareEmail — the silent-failure
            // mode is the worst UX (recipient waits for an email that never
            // arrives), so log exception class + file:line + mailer config
            // to make the post-mortem trivial.
            Log::error('AgentShare portal email failed', [
                'to'          => $recipientEmail,
                'mailer'      => config('mail.default'),
                'smtp_host'   => config('mail.mailers.smtp.host'),
                'smtp_port'   => config('mail.mailers.smtp.port'),
                'error_class' => get_class($e),
                'error'       => $e->getMessage(),
                'file'        => basename($e->getFile()) . ':' . $e->getLine(),
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

                // UX: the admin already bound this share to a specific
                // client_email when creating it. Asking the recipient to
                // retype that same address is pointless friction (and a
                // typo silently fails due to anti-enumeration). So we
                // auto-issue the OTP to the stored address and land the
                // recipient directly on the code-input step.
                //
                // EXCEPTION: multi-recipient shares (additional_emails set).
                // We don't know which teammate just opened the browser, so
                // we keep the email-input step — whichever address the
                // visitor types (if it's on the allowlist) gets the code.
                // This avoids leaking the code to the primary recipient
                // when a teammate opens the link.
                if (!$share->hasMultipleRecipients()) {
                    $authorisedEmail = strtolower(trim((string) $share->client_email));
                    if ($authorisedEmail !== '') {
                        $svc->issueOtp($share, $authorisedEmail, $sessionId, $request);

                        return view('agent-shares.otp-challenge', [
                            'share'         => $share,
                            'new_device'    => $status === 'new_device',
                            'suggested'     => $share->client_email,
                            'otp_sent'      => true,
                            'sent_to'       => $this->maskEmail($authorisedEmail),
                            'auto_issued'   => true,
                            'entered_email' => $authorisedEmail,
                        ]);
                    }
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

        $email = strtolower(trim((string) $request->input('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['email' => 'Email inválido.'])->withInput();
        }

        $sessionId = $this->sharePublicSessionId($share);
        app(AgentShareAccessService::class)->issueOtp($share, $email, $sessionId, $request);

        // Always report success to the client — we never reveal whether the
        // email matched, to avoid enumeration.
        //
        // MULTI-RECIPIENT NOTE: we pass $entered_email explicitly so step 2's
        // hidden email input has a reliable source — `request()->input()`
        // only works on THIS render; after a failed code (which redirects via
        // back()) the request no longer carries the email field.
        return view('agent-shares.otp-challenge', [
            'share'         => $share,
            'otp_sent'      => true,
            'sent_to'       => $this->maskEmail($email),
            'entered_email' => $email,
        ]);
    }

    // ── PUBLIC: Verify OTP + create trusted session ──────────────────────────
    public function verifyOtp(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();
        if (!$share->isValid()) abort(403, 'Link inválido.');

        $email = strtolower(trim((string) $request->input('email', '')));
        $code  = preg_replace('/\D/', '', (string) $request->input('code', ''));

        // Shared renderer for "still on step 2, please try again" — avoids
        // back()ing to /otp/request (a POST-only URL → 405) and keeps the
        // recipient's typed email pinned on the retry form.
        $rerenderStep2 = function (string $message) use ($share, $email) {
            return view('agent-shares.otp-challenge', [
                'share'         => $share,
                'otp_sent'      => true,
                'sent_to'       => $email !== '' ? $this->maskEmail($email) : 'o email indicado',
                'entered_email' => $email,
                'error_code'    => $message,
            ]);
        };

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $rerenderStep2('Volta ao passo anterior e introduz o teu email.');
        }
        if (strlen($code) !== 6) {
            return $rerenderStep2('O código tem de ter 6 dígitos.');
        }

        $sessionId = $this->sharePublicSessionId($share);
        $ok = app(AgentShareAccessService::class)->verifyOtp($share, $email, $sessionId, $code, $request);

        if (!$ok) {
            return $rerenderStep2('Código incorrecto ou expirado.');
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
