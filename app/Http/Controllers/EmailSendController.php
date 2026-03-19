<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailSendController extends Controller
{
    /**
     * POST /api/email/send
     * Send an email directly from the ClawYard chat interface.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'to'      => 'required|email',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string|max:20000',
            'cc'      => 'nullable|email',
        ]);

        try {
            $to      = $request->input('to');
            $cc      = $request->input('cc');
            $subject = $request->input('subject');
            $body    = $request->input('body');
            $from    = config('mail.from.address', 'info@clawyard.com');
            $name    = config('mail.from.name', 'ClawYard Maritime');

            Mail::html($this->wrapHtml($body, $subject), function ($mail) use ($to, $cc, $subject, $from, $name) {
                $mail->to($to)
                     ->from($from, $name)
                     ->subject($subject);

                if ($cc) {
                    $mail->cc($cc);
                }
            });

            // Log sent email
            \Log::info('ClawYard Email Sent', [
                'to'      => $to,
                'subject' => $subject,
                'user'    => auth()->user()?->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email sent to ' . $to,
            ]);

        } catch (\Exception $e) {
            \Log::error('ClawYard Email Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Wrap plain-text email body in clean HTML.
     */
    protected function wrapHtml(string $body, string $subject): string
    {
        $html = nl2br(htmlspecialchars($body));
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 14px; color: #222; line-height: 1.7; background: #f4f4f4; margin: 0; padding: 0; }
  .wrapper { background: #fff; max-width: 680px; margin: 30px auto; padding: 40px; border-radius: 8px; }
  .header { border-bottom: 3px solid #76b900; padding-bottom: 16px; margin-bottom: 24px; }
  .logo { font-size: 22px; font-weight: bold; color: #76b900; }
  .body { color: #333; }
  .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="logo">🐾 ClawYard Maritime</div>
  </div>
  <div class="body">{$html}</div>
  <div class="footer">
    ClawYard Maritime | IT Partyard LDA<br>
    Marine Spare Parts &amp; Technical Services<br>
    Setúbal, Portugal · info@clawyard.com
  </div>
</div>
</body>
</html>
HTML;
    }
}
