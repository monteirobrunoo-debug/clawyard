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
 * Sent immediately after a manager bulk-assigns one or more tenders to a
 * collaborator via the /tenders dashboard.
 *
 * Tells the recipient *what* was just assigned to them and gives them a
 * "Abrir ClawYard →" CTA so they can confirm receipt and start working.
 * This was the missing piece the user flagged: "os users do dashboard com
 * os processos atribuídos não recebem email para confirmar e entrar".
 *
 * If the collaborator has a linked User account (email match), the CTA
 * takes them straight to the dashboard after SSO/login; otherwise they'll
 * hit the login page and will need IT to provision an account. Either
 * way, the list of affected tenders is visible in the email body so the
 * information itself isn't gated behind login.
 *
 * Contrast with:
 *   - TenderCollaboratorReminder: manual super-user nudge, full active list
 *   - TenderDailyDigest: scheduled, role-aware
 *   - TenderDeadlineAlert: scheduled, single-tender deadline trigger
 * This one is event-driven: one email per bulk-assign POST.
 */
class TenderAssignedNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param TenderCollaborator $collaborator the recipient (assignee)
     * @param Collection<int, \App\Models\Tender> $tenders what was just assigned
     * @param User $sender the manager who triggered the assignment
     */
    public function __construct(
        public TenderCollaborator $collaborator,
        public Collection $tenders,
        public User $sender,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->tenders->count();
        $subject = $count === 1
            ? "🐾 ClawYard — 1 concurso atribuído a ti por {$this->sender->name}"
            : "🐾 ClawYard — {$count} concursos atribuídos a ti por {$this->sender->name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenders.assigned',
            with: [
                'collaborator' => $this->collaborator,
                'tenders'      => $this->tenders,
                'sender'       => $this->sender,
                // Used in the template to show either "Abrir dashboard" (has
                // account) or "Contacta IT para criar conta" (doesn't).
                'hasAccount'   => $this->collaborator->user_id !== null,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
