<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TokenPoolAlertMail — alerta enviado quando o pool token Anthropic
 * atinge 80% (warning) ou 100% (exhausted) do limite mensal.
 *
 * Destinatários: services.tokens.admin_emails (multi).
 * Inclui botão "Mais Tokens" que leva ao /admin/tokens para top-up.
 */
class TokenPoolAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $kind,        // 'warning' | 'exhausted'
        public array  $summary,     // TokenBudgetService::summary()
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->kind === 'exhausted'
            ? sprintf('🚨 ClawYard — pool de tokens ESGOTADO (%s)', $this->summary['period'])
            : sprintf('⚠️ ClawYard — pool de tokens em %s%% (%s)',
                $this->summary['percent_used'], $this->summary['period']);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.token-pool-alert',
            with: [
                'kind'         => $this->kind,
                'summary'      => $this->summary,
                'dashboardUrl' => $this->dashboardUrl,
            ],
        );
    }
}
