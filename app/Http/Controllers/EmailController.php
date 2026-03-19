<?php

namespace App\Http\Controllers;

use App\Mail\MaritimeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EmailController extends Controller
{
    /**
     * Send a maritime email
     */
    public function send(Request $request)
    {
        $v = Validator::make($request->all(), [
            'to'      => 'required|email',
            'subject' => 'required|string|max:255',
            'body'    => 'required|string',
            'cc'      => 'nullable|email',
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'error' => $v->errors()->first()], 422);
        }

        try {
            $mail = new MaritimeMail(
                emailSubject: $request->subject,
                emailBody:    $request->body,
                senderName:   config('mail.from.name', 'ClawYard Maritime'),
            );

            $send = Mail::to($request->to);

            if ($request->filled('cc')) {
                $send->cc($request->cc);
            }

            $send->send($mail);

            return response()->json([
                'success' => true,
                'message' => 'Email enviado para ' . $request->to,
                'to'      => $request->to,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Erro ao enviar email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview email HTML (for testing)
     */
    public function preview(Request $request)
    {
        $subject = $request->get('subject', 'Marine Spare Parts — Quote Request');
        $body    = $request->get('body', "Dear Captain,\n\nWe are pleased to offer our maritime spare parts and technical services.\n\nBest regards,\nClawYard Maritime Team");

        return new MaritimeMail($subject, $body);
    }
}
