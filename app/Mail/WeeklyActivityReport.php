<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Relatório semanal de actividade por utilizador. Disparado pelo comando
 * `clawyard:weekly-activity` (registado no schedule, à segunda às 8h).
 *
 * Conteúdo: pontos ganhos na semana, level actual, streak, agentes mais
 * usados, leads/concursos tocados, próxima meta. Encoraja a manter o uso
 * regular sem ser intrusivo (uma vez por semana).
 */
class WeeklyActivityReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param User  $recipient
     * @param array $stats {
     *   points_earned: int,
     *   total_points: int,
     *   level: int,
     *   level_name: string,
     *   next_level_name: ?string,
     *   points_to_next: int,
     *   streak: int,
     *   best_streak: int,
     *   chats: int,
     *   top_agents: array<int, array{agent: string, name: string, emoji: string, chats: int}>,
     *   leads_qualified: int,
     *   tenders_imported: int,
     *   badges_earned: array<int, string>,
     *   rank: ?int,
     * }
     */
    public function __construct(
        public User $recipient,
        public array $stats,
    ) {}

    public function envelope(): Envelope
    {
        $pts = (int) $this->stats['points_earned'];
        $subject = $pts > 0
            ? "📊 ClawYard — semana de {$pts} pts"
            : "📊 ClawYard — relatório semanal";
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.weekly-activity');
    }
}
