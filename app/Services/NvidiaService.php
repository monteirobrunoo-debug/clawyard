<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class NvidiaService
{
    protected Client $client;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey  = config('services.nvidia.api_key');
        $this->baseUrl = config('services.nvidia.base_url');
        $this->model   = config('services.nvidia.model');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Send a chat message to the NVIDIA NIM API.
     *
     * @param string $message
     * @param array  $history  [['role' => 'user'|'assistant', 'content' => '...'], ...]
     * @return string
     * @throws GuzzleException
     */
    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/chat/completions', [
            'json' => [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => 1024,
                'temperature' => 0.7,
                'top_p'       => 1,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['choices'][0]['message']['content'] ?? '';
    }
}
