<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Bridge HTTP entre clawyard e o PartYard Work Report App standalone.
 *
 * O standalone (Python Flask em /Volumes/.../Work_Report_App/) tem
 * vision API + biblioteca técnica embebida e expõe estes endpoints:
 *
 *   POST /analyze-pdf-scope   — body: PDF (multipart) → JSON scope
 *   POST /analyze-photos      — body: imagens + job ctx → JSON análise
 *   POST /suggest-photos      — body: job_data → JSON plan
 *   POST /suggest-content     — body: metadata → JSON conteúdo report
 *
 * Esta service classe encapsula as chamadas para o WorkReportAgent
 * e o WorkReportController poderem fazer handoff transparente.
 *
 * Configurar via .env:
 *   WORK_REPORT_APP_URL=http://10.0.0.5:5050
 *   WORK_REPORT_APP_TOKEN=secret  (header X-Bridge-Token)
 */
class WorkReportBridgeService
{
    private Client $http;
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.work_report.url', '');
        $this->token  = (string) config('services.work_report.token', '');

        $this->http = new Client([
            'timeout'         => 90,    // vision calls take 30-60s
            'connect_timeout' => 5,
        ]);
    }

    public function isAvailable(): bool
    {
        if ($this->baseUrl === '') return false;
        try {
            $r = $this->http->get(rtrim($this->baseUrl, '/') . '/', [
                'http_errors' => false,
                'timeout'     => 3,
            ]);
            return $r->getStatusCode() < 500;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Envia um PDF para análise de scope. Devolve o JSON do
     * standalone tal como vem (vessel, scope, deadline, etc).
     */
    public function analyzePdfScope(string $pdfBytes, string $filename = 'scope.pdf'): array
    {
        return $this->callStandalone('/analyze-pdf-scope', [
            'multipart' => [[
                'name'     => 'pdf',
                'contents' => $pdfBytes,
                'filename' => $filename,
            ]],
        ]);
    }

    /**
     * Envia imagens (cada uma como base64 string) para análise
     * pelo agente do standalone. job_context vai como JSON.
     *
     * @param array<array{type:string, source:array}> $images Anthropic-style image blocks
     */
    public function analyzePhotos(array $images, array $jobContext = []): array
    {
        return $this->callStandalone('/analyze-photos', [
            'json' => [
                'images'      => $images,
                'job_context' => $jobContext,
            ],
        ]);
    }

    public function suggestPhotos(array $jobData): array
    {
        return $this->callStandalone('/suggest-photos', ['json' => $jobData]);
    }

    public function suggestContent(array $metadata): array
    {
        return $this->callStandalone('/suggest-content', ['json' => $metadata]);
    }

    private function callStandalone(string $path, array $opts): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'error' => 'work_report_url_not_configured'];
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        // Auth header (proxy can validate)
        $headers = $opts['headers'] ?? [];
        if ($this->token !== '') $headers['X-Bridge-Token'] = $this->token;
        $opts['headers']  = $headers;
        $opts['http_errors'] = false;

        try {
            $res = $this->http->post($url, $opts);
            $status = $res->getStatusCode();
            $body   = (string) $res->getBody();

            if ($status >= 400) {
                Log::warning('WorkReportBridge: upstream error', [
                    'path'   => $path,
                    'status' => $status,
                    'body'   => mb_substr($body, 0, 250),
                ]);
                return ['ok' => false, 'error' => "http_{$status}", 'detail' => mb_substr($body, 0, 200)];
            }

            $json = json_decode($body, true);
            if (!is_array($json)) {
                return ['ok' => false, 'error' => 'non_json_response'];
            }
            return array_merge(['ok' => true], $json);
        } catch (\Throwable $e) {
            Log::error('WorkReportBridge: exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'exception: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }
}
