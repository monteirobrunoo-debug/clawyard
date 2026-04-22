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

        $mailer   = (string) config('mail.default');
        $queueCon = (string) config('queue.default');
        $cfgCache = file_exists(base_path('bootstrap/cache/config.php'))
            ? date('Y-m-d H:i:s', (int) filemtime(base_path('bootstrap/cache/config.php')))
            : '(no cache file — using fresh env)';

        $this->line('');
        $this->line('────────────────────────────────────────');
        $this->line(' ClawYard · Mail diagnostic');
        $this->line('────────────────────────────────────────');
        $this->line(' MAIL_MAILER     : ' . $mailer . ($mailer === 'log' ? '   ← ⚠️  emails go to the LOG, not SMTP!' : ''));
        $this->line(' MAIL_SCHEME     : ' . (config('mail.mailers.smtp.scheme')     ?? '(null)'));
        $this->line(' MAIL_HOST       : ' . config('mail.mailers.smtp.host'));
        $this->line(' MAIL_PORT       : ' . config('mail.mailers.smtp.port'));
        $this->line(' MAIL_USERNAME   : ' . (config('mail.mailers.smtp.username')   ?: '(empty)'));
        $this->line(' MAIL_ENCRYPTION : ' . (config('mail.mailers.smtp.encryption') ?? '(null)'));
        $this->line(' MAIL_FROM       : ' . config('mail.from.address') . ' <' . config('mail.from.name') . '>');
        $this->line(' QUEUE_CONNECTION: ' . $queueCon . ($queueCon !== 'sync' ? '   ← needs worker to flush queued mail' : ''));
        $this->line(' config:cache    : ' . $cfgCache);
        $this->line(' → Recipient     : ' . $to);
        $this->line('────────────────────────────────────────');

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log → the test will succeed but the email');
            $this->warn('will be written to storage/logs/laravel.log instead of');
            $this->warn('being sent. Set MAIL_MAILER=smtp (or mailgun/ses/…) in');
            $this->warn('Forge → Environment and redeploy.');
            $this->line('');
        }

        try {
            Mail::raw(
                "Mail de diagnóstico do ClawYard.\n\nSe recebeste este email, o SMTP está correctamente configurado no Forge.\n\nEnviado a " . now()->toDateTimeString(),
                function ($msg) use ($to, $subject) {
                    $msg->to($to)->subject($subject);
                }
            );

            $this->info('✅ OK — email enviado com sucesso');
            $this->line('   Verifica a caixa ' . $to . ' (inclui pasta Spam/Lixo Electrónico).');
            $this->tailRecentMailFailures();
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ FALHOU: ' . get_class($e));
            $this->error('   ' . $e->getMessage());
            $this->line('   @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $this->line('');
            $this->line('Dicas rápidas:');
            $this->line(' • "535 Authentication failed" → password errada ou username precisa ser só a parte local');
            $this->line(' • "Connection could not be established" → tenta porta 465 + MAIL_ENCRYPTION=ssl');
            $this->line(' • "550 relay denied" → IP do servidor não está whitelisted');
            $this->line(' • "SSL: certificate verify failed" → admin do mail precisa renovar o certificado');
            $this->line(' • "Expected response code 2xx got ..."   → scheme/porta desalinhados (smtp:587+tls OU smtps:465+ssl)');
            $this->tailRecentMailFailures();
            return self::FAILURE;
        }
    }

    /**
     * Grab the last few "AgentShare … failed" lines from the Laravel log and
     * print them. Saves the operator one `tail` command when diagnosing why
     * a share didn't reach the recipient.
     */
    private function tailRecentMailFailures(): void
    {
        $log = storage_path('logs/laravel.log');
        if (!is_readable($log)) return;

        // Read last 200KB of the log only — plenty for recent entries without
        // the risk of loading a 50MB file into memory.
        $size   = (int) filesize($log);
        $offset = max(0, $size - 200_000);
        $fh     = @fopen($log, 'r');
        if (!$fh) return;
        @fseek($fh, $offset);
        $chunk = (string) @stream_get_contents($fh);
        @fclose($fh);
        if ($chunk === '') return;

        $lines = preg_split('/\r?\n/', $chunk) ?: [];
        $hits  = [];
        foreach ($lines as $l) {
            if (stripos($l, 'AgentShare') !== false && stripos($l, 'failed') !== false) {
                $hits[] = $l;
            } elseif (stripos($l, 'OTP email failed') !== false) {
                $hits[] = $l;
            }
        }
        if (!$hits) return;

        $this->line('');
        $this->line('Recent share/OTP send failures in laravel.log (last 200KB):');
        foreach (array_slice($hits, -5) as $h) {
            $this->line('  · ' . mb_substr($h, 0, 220));
        }
    }
}
