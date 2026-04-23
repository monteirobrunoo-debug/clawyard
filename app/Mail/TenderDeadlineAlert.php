<?php

namespace App\Mail;

use App\Models\Tender;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-shot deadline alert sent ~24h before a tender's deadline.
 *
 * Scope (per user decision):
 *   • Only ONE reminder per tender lifetime — de-duped via the
 *     Tender.deadline_alert_sent_at timestamp.
 *   • Only sent to the assigned collaborator (not manager) to avoid
 *     duplicating what the manager already gets in the daily digest.
 */
class TenderDeadlineAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tender $tender,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        $hours = $this->tender->deadline_at
            ? max(0, (int) now()->diffInHours($this->tender->deadline_at, false))
            : 0;

        return new Envelope(
            subject: "⏰ Deadline em {$hours}h — {$this->tender->reference} — "
                . \Illuminate\Support\Str::limit($this->tender->title, 60),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenders.deadline-alert',
            with: [
                'tender'        => $this->tender,
                'recipientName' => $this->recipientName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
