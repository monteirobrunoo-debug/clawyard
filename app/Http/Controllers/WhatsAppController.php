<?php

namespace App\Http\Controllers;

use App\Agents\AgentManager;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WhatsAppController extends Controller
{
    protected AgentManager $agentManager;
    protected Client $client;

    public function __construct(AgentManager $agentManager)
    {
        $this->agentManager = $agentManager;
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'headers'  => [
                'Authorization' => 'Bearer ' . config('services.whatsapp.token'),
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    /**
     * Webhook verification (GET)
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Receive incoming WhatsApp messages (POST)
     *
     * SECURITY (C5): Meta signs every webhook payload with HMAC-SHA256 using
     * the app secret. Without verifying the X-Hub-Signature-256 header, any
     * attacker can post forged WhatsApp messages to trigger agent actions
     * (replies, SAP queries, etc.) on arbitrary phone numbers.
     *
     * Configure META_APP_SECRET in .env (same value as in the Meta dev portal).
     * If the secret is not configured we fail closed and reject every POST.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            \Log::warning('WhatsApp webhook: invalid or missing signature', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $body = $request->all();

        $entry   = $body['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value   = $changes['value'] ?? null;

        if (!isset($value['messages'][0])) {
            return response()->json(['status' => 'no_message']);
        }

        $message = $value['messages'][0];
        $from    = $message['from'];
        $text    = $message['text']['body'] ?? '';

        if (empty($text)) {
            return response()->json(['status' => 'no_text']);
        }

        // Route to best agent
        $agent = $this->agentManager->route($text);
        $reply = $agent->chat($text);

        // Send reply back via WhatsApp
        $this->sendMessage($from, $reply);

        return response()->json(['status' => 'sent', 'agent' => $agent->getName()]);
    }

    /**
     * HMAC-SHA256 signature check matching Meta's specification.
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret') ?: env('META_APP_SECRET');
        if (!$secret) {
            // Fail closed — better to miss a legitimate webhook than to accept
            // unsigned traffic in production.
            return false;
        }

        $header = $request->header('X-Hub-Signature-256', '');
        if (!$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $header);
    }

    protected function sendMessage(string $to, string $text): void
    {
        $phoneId = config('services.whatsapp.phone_id');

        $this->client->post("{$phoneId}/messages", [
            'json' => [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $text],
            ],
        ]);
    }
}
