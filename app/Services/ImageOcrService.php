<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * OCR para imagens (JPG/PNG) capturadas via câmara do telemóvel ou
 * upload directo. Usa Claude Vision com prompt focado em extracção
 * literal — pretendemos texto cru, não interpretação.
 *
 * Lifecycle:
 *   1. TenderAttachmentController detecta mime_type=image/* (ou ext
 *      jpg/jpeg/png/webp)
 *   2. Chama ImageOcrService::extract($absolutePath)
 *   3. Recebe ['ok' => bool, 'text' => string, 'error' => string|null]
 *   4. Se ok=true → STATUS_OK + extracted_text
 *      Se ok=false → STATUS_FAILED + extraction_error
 *
 * Custo típico: ~$0.005 por imagem A4 (Claude Sonnet 4.6, vision).
 */
class ImageOcrService
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB raw image bytes
    private const SUPPORTED_MIMES = [
        'image/jpeg' => 'image/jpeg',
        'image/jpg'  => 'image/jpeg',
        'image/png'  => 'image/png',
        'image/webp' => 'image/webp',
        'image/gif'  => 'image/gif',
    ];

    private Client $http;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', '');
        $this->model  = (string) config('services.anthropic.model', 'claude-sonnet-4-6');
        $this->http   = new Client([
            'base_uri'    => (string) config('services.anthropic.base_uri', 'https://api.anthropic.com'),
            'timeout'     => 60,
            'http_errors' => false,
        ]);
    }

    /**
     * Extrai texto de uma imagem usando Claude Vision.
     *
     * @return array{ok:bool, text:string, error:string|null}
     */
    public function extract(string $absolutePath, ?string $mimeHint = null): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'text' => '', 'error' => 'ANTHROPIC_API_KEY not configured'];
        }

        if (!is_file($absolutePath)) {
            return ['ok' => false, 'text' => '', 'error' => 'file_not_found'];
        }

        $size = filesize($absolutePath);
        if ($size === false || $size <= 0) {
            return ['ok' => false, 'text' => '', 'error' => 'empty_file'];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'text' => '', 'error' => 'image_too_large (' . $size . ' bytes > ' . self::MAX_BYTES . ')'];
        }

        // Resolver mime: hint do controller > detect via finfo > fallback jpeg
        $mime = $mimeHint;
        if (!$mime || !isset(self::SUPPORTED_MIMES[strtolower($mime)])) {
            $detected = @mime_content_type($absolutePath) ?: '';
            $mime     = self::SUPPORTED_MIMES[strtolower($detected)] ?? 'image/jpeg';
        } else {
            $mime = self::SUPPORTED_MIMES[strtolower($mime)];
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'text' => '', 'error' => 'read_failed'];
        }
        $b64 = base64_encode($bytes);

        $system = 'És um OCR de alta-fidelidade. Recebes UMA imagem (foto de documento, '
                . 'fax, screenshot, RFQ impresso, etc.) e devolves o texto LITERAL '
                . 'que aparece nela. Sem interpretação, sem resumo, sem adicionar nada. '
                . 'Mantém quebras de linha onde aparecem. Se houver tabelas, usa '
                . 'separação por | e linhas. Se a imagem não tiver texto legível, '
                . 'devolve exactamente a string "(sem texto detectado)".';

        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'system'     => $system,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mime,
                            'data'       => $b64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Extrai o texto LITERAL desta imagem.',
                    ],
                ],
            ]],
        ];

        try {
            $res = $this->http->post('/v1/messages', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                    'accept'            => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ImageOcrService: HTTP error', ['error' => $e->getMessage()]);
            return ['ok' => false, 'text' => '', 'error' => 'http_error: ' . $e->getMessage()];
        }

        $status = $res->getStatusCode();
        $raw    = (string) $res->getBody();
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'text' => '', 'error' => 'api_status_' . $status . ': ' . mb_substr($raw, 0, 200)];
        }

        $data = json_decode($raw, true);
        $text = (string) ($data['content'][0]['text'] ?? '');
        $text = trim($text);

        if ($text === '' || str_contains(mb_strtolower($text), '(sem texto detectado)')) {
            return ['ok' => true, 'text' => '', 'error' => null];
        }

        return ['ok' => true, 'text' => $text, 'error' => null];
    }
}
