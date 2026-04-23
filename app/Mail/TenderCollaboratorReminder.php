<?php

namespace App\Mail;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Manual "super-user nudge" email. Triggered from /tenders/overview when
 * a manager clicks the 📧 button next to a collaborator card.
 *
 * Contrast with TenderDailyDigest: that one is scheduled, role-aware,
 * and covers everyone. This one is ad-hoc, targets a single
 * TenderCollaborator (which may or may not have a linked User), and
 * lists only their currently-active, not-yet-expired tenders.
 */
class TenderCollaboratorReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param TenderCollaborator $collaborator
     * @param Collection<int, \App\Models\Tender> $tenders active, not-expired bucket
     * @param User $sender the manager who triggered the reminder — shown in the footer
     */
    public function __construct(
        public TenderCollaborator $collaborator,
        public Collection $tenders,
        public User $sender,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->tenders->count();
        $overdue = $this->tenders->filter(fn($t) => $t->urgency_bucket === 'overdue')->count();

        $subject = "📌 Lembrete: {$count} concurso" . ($count === 1 ? '' : 's') . " activo" . ($count === 1 ? '' : 's');
        if ($overdue > 0) {
            $subject .= " — {$overdue} em atraso";
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenders.collaborator-reminder',
            with: [
                'collaborator' => $this->collaborator,
                'tenders'      => $this->tenders,
                'sender'       => $this->sender,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
