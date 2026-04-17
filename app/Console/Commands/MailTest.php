<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Quick end-to-end SMTP check — used to validate .env mail credentials
 * from the Forge "Commands" panel without worrying about shell escaping.
 *
 *   php artisan mail:test bruno.monteiro@hp-group.org
 */
class MailTest extends Command
{
    protected $signature   = 'mail:test {to : Destination email} {--subject=ClawYard SMTP Test}';
    protected $description = 'Send a test email to verify SMTP credentials in .env';

    public function handle(): int
    {
        $to      = $this->argument('to');
        $subject = (string) $this->option('subject');

        $this->line('');
        $this->line('────────────────────────────────────────');
        $this->line(' ClawYard · Mail diagnostic');
        $this->line('────────────────────────────────────────');
        $this->line(' MAIL_MAILER     : ' . config('mail.default'));
        $this->line(' MAIL_HOST       : ' . config('mail.mailers.smtp.host'));
        $this->line(' MAIL_PORT       : ' . config('mail.mailers.smtp.port'));
        $this->line(' MAIL_USERNAME   : ' . config('mail.mailers.smtp.username'));
        $this->line(' MAIL_ENCRYPTION : ' . (config('mail.mailers.smtp.encryption') ?? '(null)'));
        $this->line(' MAIL_FROM       : ' . config('mail.from.address') . ' <' . config('mail.from.name') . '>');
        $this->line(' → Recipient     : ' . $to);
        $this->line('────────────────────────────────────────');

        try {
            Mail::raw(
                "Mail de diagnóstico do ClawYard.\n\nSe recebeste este email, o SMTP está correctamente configurado no Forge.\n\nEnviado a " . now()->toDateTimeString(),
                function ($msg) use ($to, $subject) {
                    $msg->to($to)->subject($subject);
                }
            );

            $this->info('✅ OK — email enviado com sucesso');
            $this->line('   Verifica a caixa ' . $to . ' (inclui pasta Spam/Lixo Electrónico).');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ FALHOU: ' . $e->getMessage());
            $this->line('');
            $this->line('Dicas rápidas:');
            $this->line(' • "535 Authentication failed" → password errada ou username precisa ser só a parte local');
            $this->line(' • "Connection could not be established" → tenta porta 465 + MAIL_ENCRYPTION=ssl');
            $this->line(' • "550 relay denied" → IP do servidor não está whitelisted');
            $this->line(' • "SSL: certificate verify failed" → admin do mail precisa renovar o certificado');
            return self::FAILURE;
        }
    }
}
