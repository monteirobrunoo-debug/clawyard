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
     */
    public function webhook(Request $request): JsonResponse
    {
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
