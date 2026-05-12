<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email-delivered OTP for users without an authenticator app set up.
 *
 * Sent during login challenge when user has NO TOTP secret. Code is
 * 6 digits, valid 10 minutes, single-use. Anti-abuse: rate-limited
 * to 3 sends per 15 minutes per user_id via the cache key the
 * controller checks before dispatching this mail.
 *
 * Content is intentionally minimal — no links, no action buttons.
 * If a user gets this without trying to log in, the only sensible
 * response is "ignore + change password", which we state in copy.
 */
class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $code,
        public int    $ttlMinutes = 10,
        public string $ip = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ClawYard — código de acesso {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.login-otp');
    }
}
