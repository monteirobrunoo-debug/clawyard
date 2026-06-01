<?php

namespace App\Http\Controllers;

use App\Services\EmailEncryptionService;
use App\Services\KyberEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class EmailSendController extends Controller
{
    public function __construct(
        private EmailEncryptionService $encSvc,
        private KyberEncryptionService $kyber,
    ) {}

    /**
     * POST /api/email/send
     *
     * Send an email from the ClawYard chat interface.
     * When the recipient has a stored Kyber-1024 public key (or the caller
     * passes `encrypt: true`), the message is encrypted with
     * Kyber-1024 + AES-256-GCM before transmission.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'to'            => 'required|email',
            'subject'       => 'required|string|max:500',
            'body'          => 'nullable|string|max:20000',
            'cc'            => 'nullable|email',
            'encrypt'       => 'nullable|boolean',
            'generate_key'  => 'nullable|boolean', // generate fresh Kyber keypair for this email
            'raw_html'      => 'nullable|string', // pre-built encrypted HTML (from Kyber agent)
            'attachments'   => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480', // 20 MB per file
        ]);

        try {
            $to      = $request->input('to');
            $cc      = $request->input('cc');
            $subject = $request->input('subject');
            $body    = $request->input('body');
            $from    = config('mail.from.address', 'no-reply@hp-group.org');
            $name    = config('mail.from.name', 'HP-Group / ClawYard');

            // ── Generate-key path (compose card) — fresh keypair per email ──
            if ($request->boolean('generate_key', false)) {
                $pair           = $this->kyber->generateKeyPair();
                $uploadedFiles  = $request->file('attachments') ?? [];
                $encAttachments = array_map(fn($f) => [
                    'name' => $f->getClientOriginalName(),
                    'mime' => $f->getMimeType(),
                    'data' => base64_encode($f->get()),
                ], $uploadedFiles);
                $package      = $this->encSvc->encryptEmail($subject, $body ?? '', $pair['public_key'], $encAttachments);
                $token        = $this->encSvc->storePackage($package);
                $htmlBody     = $this->encSvc->buildOutlookHtml($package, $name, '', $token);
                $emailSubject = '[Encrypted] ' . $subject;
                $encrypted    = true;
                $secretKey    = $pair['secret_key'];
                $decryptUrl   = 'https://clawyard.partyard.eu/decrypt/' . $token;
                $plainAttachments = [];

            // ── Pre-built encrypted HTML (from Kyber agent card) ───────────
            } elseif ($request->filled('raw_html')) {
                $htmlBody     = $request->input('raw_html');
                $emailSubject = str_starts_with($subject, '[Encrypted]') ? $subject : '[Encrypted] ' . $subject;
                $encrypted    = true;
                $plainAttachments = [];
            } else {
                // ── Standard encryption path ────────────────────────────────
                // Priority: sender's own key → recipient's key → plaintext
                $wantsEncrypt = $request->boolean('encrypt', false);
                $senderKey    = $this->encSvc->getPublicKey(auth()->user()->email);
                $recipientKey = $this->encSvc->getPublicKey($to);
                $encryptKey   = $wantsEncrypt
                    ? ($senderKey ?? $recipientKey)
                    : $recipientKey;
                $encrypted = $encryptKey !== null;

                // ── Attachments ────────────────────────────────────────────
                $uploadedFiles    = $request->file('attachments') ?? [];
                $plainAttachments = [];

                if ($encrypted) {
                    $encAttachments = array_map(fn($f) => [
                        'name' => $f->getClientOriginalName(),
                        'mime' => $f->getMimeType(),
                        'data' => base64_encode($f->get()),
                    ], $uploadedFiles);
                    $package      = $this->encSvc->encryptEmail($subject, $body ?? '', $encryptKey, $encAttachments);
                    $token        = $this->encSvc->storePackage($package);
                    $htmlBody     = $this->encSvc->buildOutlookHtml($package, $name, '', $token);
                    $emailSubject = '[Encrypted] ' . $subject;
                    $decryptUrl   = 'https://clawyard.partyard.eu/decrypt/' . $token;
                } else {
                    $plainAttachments = $uploadedFiles;
                    $htmlBody         = $this->wrapHtml($body ?? '', $subject);
                    $emailSubject     = $subject;
                    $decryptUrl       = null;
                }
            }

            // ── Send ───────────────────────────────────────────────────────
            Mail::html($htmlBody, function ($mail) use ($to, $cc, $emailSubject, $from, $name, $plainAttachments) {
                $mail->to($to)
                     ->from($from, $name)
                     ->subject($emailSubject);

                if ($cc) {
                    $mail->cc($cc);
                }

                foreach ($plainAttachments as $file) {
                    $mail->attachData(
                        $file->get(),
                        $file->getClientOriginalName(),
                        ['mime' => $file->getMimeType()]
                    );
                }
            });

            \Log::info('ClawYard Email Sent', [
                'to'        => $to,
                'subject'   => $subject,
                'encrypted' => $encrypted,
                'user'      => auth()->user()?->email,
            ]);

            return response()->json(array_filter([
                'success'      => true,
                'message'      => 'Email sent to ' . $to,
                'encrypted'    => $encrypted,
                'decrypt_url'  => $decryptUrl ?? null,
                'secret_key'   => $secretKey ?? null,
            ], fn($v) => $v !== null));

        } catch (\Exception $e) {
            \Log::error('ClawYard Email Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Render the (markdown) email body to clean, email-friendly HTML.
     *
     * Projecto "emails bonitos" Fase 1 (2026-06-01): os agentes escrevem em
     * MARKDOWN. Antes fazíamos nl2br(htmlspecialchars(...)) → o markdown saía
     * CRU (## títulos, **negrito**, listas -). Agora renderizamos markdown→HTML
     * (GFM: tabelas, listas, links) com config SEGURA — html_input='strip' e
     * sem links unsafe, porque o body vem de LLM/user — e estilos próprios para
     * email. (Fase 2 fará inline do CSS para compatibilidade total com Outlook.)
     */
    protected function wrapHtml(string $body, string $subject): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',   // remove HTML cru (anti-XSS — body vem de LLM)
            'allow_unsafe_links' => false,     // bloqueia javascript:, data:, etc.
        ]);
        $html = (string) $converter->convert($body);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; font-size: 15px; color: #222; line-height: 1.7; background: #f4f4f4; margin: 0; padding: 0; }
  .wrapper { background: #fff; max-width: 680px; margin: 30px auto; padding: 40px; border-radius: 8px; }
  .header { border-bottom: 3px solid #76b900; padding-bottom: 16px; margin-bottom: 24px; }
  .logo { font-size: 22px; font-weight: bold; color: #76b900; }
  .body { color: #333; }
  .body h1 { font-size: 22px; margin: 24px 0 12px; color: #1a1a1a; line-height: 1.3; }
  .body h2 { font-size: 18px; margin: 20px 0 10px; color: #1a1a1a; line-height: 1.3; }
  .body h3 { font-size: 16px; margin: 16px 0 8px; color: #333; }
  .body p { margin: 0 0 14px; }
  .body ul, .body ol { margin: 0 0 14px; padding-left: 22px; }
  .body li { margin-bottom: 6px; }
  .body strong { color: #1a1a1a; }
  .body a { color: #2563eb; text-decoration: underline; }
  .body blockquote { margin: 0 0 14px; padding: 8px 16px; border-left: 3px solid #76b900; background: #f6f9f0; color: #555; }
  .body code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; font-family: Consolas, monospace; font-size: 13px; }
  .body pre { background: #f6f8fa; padding: 14px; border-radius: 6px; overflow-x: auto; }
  .body pre code { background: none; padding: 0; }
  .body table { border-collapse: collapse; width: 100%; margin: 0 0 14px; }
  .body th, .body td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; font-size: 13px; }
  .body th { background: #f4f4f4; font-weight: 600; }
  .body hr { border: 0; border-top: 1px solid #eee; margin: 20px 0; }
  .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="logo">🐾 ClawYard</div>
  </div>
  <div class="body">{$html}</div>
  <div class="footer">
    ClawYard | IT Partyard LDA<br>
    Marine Spare Parts &amp; Technical Services<br>
    Setúbal, Portugal · no-reply@hp-group.org
  </div>
</div>
</body>
</html>
HTML;
    }
}
