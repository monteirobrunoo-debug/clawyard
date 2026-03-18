<?php

namespace App\Agents;

use GuzzleHttp\Client;

class NvidiaAgent implements AgentInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('services.nvidia.base_url'),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.nvidia.api_key'),
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/chat/completions', [
            'json' => [
                'model'       => config('services.nvidia.model'),
                'messages'    => $messages,
                'max_tokens'  => 1024,
                'temperature' => 0.7,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    public function getName(): string { return 'nvidia'; }
    public function getModel(): string { return config('services.nvidia.model'); }
}
