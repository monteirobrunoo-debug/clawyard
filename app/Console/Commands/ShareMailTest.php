<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Simulates the exact HTML + plain-text multipart send path used by
 * AgentShareController::sendShareEmail, without needing to go through the
 * admin UI or have an AgentShare row in the DB.
 *
 * The real share email uses:
 *   Mail::send([], [], function ($mail) { ... });
 *     + $mail->getSymfonyMessage()->html(...);
 *     + $mail->getSymfonyMessage()->text(...);
 *
 * That is a different code path from Mail::raw (which mail:test uses), and
 * some SMTP servers / corporate spam filters reject multipart HTML while
 * accepting plain text. This command isolates that path.
 *
 *   php artisan share:test-send destinatario@exemplo.com
 *
 * Exit code 0 on success, 1 on failure — with exception class + file:line
 * so the operator can paste the output and get an instant diagnosis.
 */
class ShareMailTest extends Command
{
    protected $signature   = 'share:test-send {to : Recipient email}';
    protected $description = 'Simulate the AgentShare HTML+plain-text email send path end-to-end';

    public function handle(): int
    {
        $to       = (string) $this->argument('to');
        $fromAddr = (string) config('mail.from.address', 'no-reply@hp-group.org');
        $fromName = (string) config('mail.from.name',    'HP-Group / ClawYard');
        $replyTo  = (string) (config('mail.reply_to.address') ?: $fromAddr);
        $subject  = '[ClawYard] Teste do caminho de envio da partilha';

        // Minimal HTML that mirrors the structure of the real share email —
        // a <div> wrapper, inline styles, a CTA button, a footer. Keep it
        // small so spam filters can't blame body size.
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#222;">
<div style="max-width:640px;margin:24px auto;padding:32px;background:#fff;border-radius:8px;">
  <h2 style="color:#76b900;border-bottom:3px solid #76b900;padding-bottom:10px;">🐾 ClawYard — Diagnóstico</h2>
  <p>Este email é enviado pelo comando <code>share:test-send</code>.</p>
  <p>Reproduz <strong>exatamente</strong> o caminho de envio usado pela partilha
  real: <code>Mail::send([], [], closure)</code> com
  <code>getSymfonyMessage()-&gt;html()</code> + <code>-&gt;text()</code>.</p>
  <p style="text-align:center;margin:22px 0;">
    <a href="https://clawyard.partyard.eu" style="display:inline-block;background:#76b900;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;">Botão de teste</a>
  </p>
  <p style="font-size:12px;color:#888;margin-top:24px;border-top:1px solid #eee;padding-top:12px;">
    Se chegou às duas caixas (gmail e hp-group) → o caminho multipart está OK
    e o problema da partilha é outro (validação, skip_email, destinatário
    não autorizado). Se <strong>não</strong> chegar → o servidor SMTP está a
    rejeitar emails HTML mas aceita texto puro; aí é filtro anti-spam.
  </p>
</div>
</body>
</html>
HTML;

        $plain = "ClawYard — Diagnostico\n\n"
               . "Este email testa o caminho HTML+plain-text usado pela partilha real.\n\n"
               . "Se chegou -> caminho OK, problema da partilha esta noutro lado.\n"
               . "Se nao chegou -> filtro anti-spam a rejeitar HTML.\n\n"
               . "Enviado: " . now()->toDateTimeString();

        $this->line('');
        $this->line('────────────────────────────────────────');
        $this->line(' ClawYard · Share-path mail diagnostic');
        $this->line('────────────────────────────────────────');
        $this->line(' MAIL_MAILER     : ' . config('mail.default'));
        $this->line(' MAIL_HOST       : ' . config('mail.mailers.smtp.host'));
        $this->line(' MAIL_PORT       : ' . config('mail.mailers.smtp.port'));
        $this->line(' MAIL_USERNAME   : ' . (config('mail.mailers.smtp.username') ?: '(empty)'));
        $this->line(' MAIL_FROM       : ' . $fromAddr . ' <' . $fromName . '>');
        $this->line(' REPLY_TO        : ' . $replyTo);
        $this->line(' SUBJECT         : ' . $subject);
        $this->line(' → Recipient     : ' . $to);
        $this->line(' Body size       : ' . strlen($html) . ' bytes HTML / ' . strlen($plain) . ' bytes plain');
        $this->line('────────────────────────────────────────');

        try {
            // EXACTLY mirrors AgentShareController::sendShareEmail line 401-412.
            // Any exception here is the same exception the share flow would
            // raise — except it surfaces to this console instead of being
            // caught and logged as "AgentShare email failed".
            Mail::send([], [], function ($mail) use ($to, $fromAddr, $fromName, $replyTo, $subject, $html, $plain) {
                $mail->to($to)
                     ->from($fromAddr, $fromName)
                     ->replyTo($replyTo, $fromName)
                     ->subject($subject);
                $symfony = $mail->getSymfonyMessage();
                $symfony->html($html, 'utf-8');
                $symfony->text($plain, 'utf-8');
            });

            $this->info('✅ OK — share-path email enviado com sucesso');
            $this->line('   Verifica ' . $to . ' (inclui Spam/Lixo Electrónico).');
            $this->line('');
            $this->line('Conclusão:');
            $this->line(' • Se este email CHEGAR mas a partilha real continuar a não chegar,');
            $this->line('   o problema está fora do envio — provavelmente validação do recipient,');
            $this->line('   skip_email=true algures, ou o destinatário não está em');
            $this->line('   authorisedEmails(). Nesse caso cola o último log:');
            $this->line('   tail -n 120 storage/logs/laravel.log | grep AgentShare');
            $this->line(' • Se este email NÃO CHEGAR, o servidor SMTP aceita texto');
            $this->line('   (mail:test passou) mas rejeita/filtra HTML multipart. Atacar:');
            $this->line('   SPF/DKIM/DMARC do domínio hp-group.org no destinatário.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ FALHOU: ' . get_class($e));
            $this->error('   ' . $e->getMessage());
            $this->line('   @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $this->line('');
            $this->line('Causa provável pelo tipo de erro:');

            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'authentication') || str_contains($msg, '535')) {
                $this->line(' → Credenciais SMTP inválidas. Verifica MAIL_PASSWORD no Forge Environment.');
            } elseif (str_contains($msg, 'relay') || str_contains($msg, '550') || str_contains($msg, '554')) {
                $this->line(' → Servidor recusa encaminhar. O destinatário está fora do domínio');
                $this->line('   autorizado OU o FROM não corresponde à conta autenticada.');
            } elseif (str_contains($msg, 'connection') || str_contains($msg, 'timed out')) {
                $this->line(' → Rede/DNS: mail.hp-group.org inalcançável a partir da droplet.');
                $this->line('   Testa:  nc -zv mail.hp-group.org 587');
            } elseif (str_contains($msg, 'certificate') || str_contains($msg, 'ssl') || str_contains($msg, 'tls')) {
                $this->line(' → TLS mismatch. Tenta MAIL_ENCRYPTION=tls explicitamente,');
                $this->line('   ou MAIL_PORT=465 + MAIL_ENCRYPTION=ssl.');
            } else {
                $this->line(' → Exceção inesperada — cola tudo na conversa para diagnóstico manual.');
            }
            return self::FAILURE;
        }
    }
}
