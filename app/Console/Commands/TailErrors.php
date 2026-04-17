<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Surface the most recent Laravel errors + run a targeted Anthropic ping.
 *
 *   php artisan tail:errors          → last 30 log lines tagged ERROR
 *   php artisan tail:errors engineer → filter by agent name
 *
 * Designed to be safe to paste in Forge's Commands box — the output is
 * self-contained so we can see exactly why a stream is dying on the
 * production box.
 */
class TailErrors extends Command
{
    protected $signature   = 'tail:errors {filter?} {--ping : Also test Anthropic API connectivity}';
    protected $description = 'Show recent Laravel errors + optional Anthropic ping test';

    public function handle(): int
    {
        $filter = $this->argument('filter');
        $log    = storage_path('logs/laravel.log');

        $this->line('');
        $this->line('═══════════════════════════════════════════════');
        $this->line(' ClawYard · Error tail');
        $this->line('═══════════════════════════════════════════════');

        if (!file_exists($log)) {
            $this->error(' ❌ log file not found: ' . $log);
        } else {
            $size = round(filesize($log) / 1024 / 1024, 1);
            $this->line(" log file: {$log} ({$size} MB)");
            $this->line('───────────────────────────────────────────────');

            // Read last ~200 KB (plenty for the last ~50 entries)
            $fp = fopen($log, 'r');
            fseek($fp, max(0, filesize($log) - 200_000));
            $chunk = fread($fp, 200_000);
            fclose($fp);

            $lines = explode("\n", $chunk);
            $take  = 40;
            $out   = [];
            foreach (array_reverse($lines) as $line) {
                if (count($out) >= $take) break;
                if (!str_contains($line, '.ERROR:') && !str_contains($line, '.CRITICAL:')) continue;
                if ($filter && !stripos($line, $filter)) continue;
                $out[] = $line;
            }

            if (empty($out)) {
                $this->info(' ✅ no recent errors in log');
            } else {
                foreach (array_reverse($out) as $line) {
                    // Soft-wrap for readability
                    $short = mb_substr($line, 0, 400);
                    $this->line(' · ' . $short . (mb_strlen($line) > 400 ? '…' : ''));
                }
            }
        }

        // ── Optional Anthropic ping ────────────────────────────
        if ($this->option('ping')) {
            $this->line('');
            $this->info('▸ Anthropic API ping');
            $key = config('services.anthropic.api_key');
            if (!$key) {
                $this->error('  ❌ ANTHROPIC_API_KEY is empty');
                return self::FAILURE;
            }
            $this->line('  key prefix : ' . substr($key, 0, 10) . '…');
            $this->line('  model      : ' . config('services.anthropic.model', 'claude-sonnet-4-6'));

            try {
                $t = microtime(true);
                $client = new Client(['base_uri' => 'https://api.anthropic.com', 'timeout' => 20]);
                $res    = $client->post('/v1/messages', [
                    'headers' => [
                        'x-api-key'         => $key,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ],
                    'json' => [
                        'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                        'max_tokens' => 20,
                        'messages'   => [['role' => 'user', 'content' => 'say ok']],
                    ],
                    'http_errors' => false,
                ]);
                $dt   = round((microtime(true) - $t) * 1000);
                $code = $res->getStatusCode();
                $body = $res->getBody()->getContents();
                if ($code === 200) {
                    $data = json_decode($body, true);
                    $txt  = $data['content'][0]['text'] ?? '';
                    $this->info("  ✅ {$code} in {$dt} ms — reply: {$txt}");
                } else {
                    $this->error("  ❌ {$code} in {$dt} ms");
                    $this->line('  body: ' . mb_substr($body, 0, 600));
                }
            } catch (\Throwable $e) {
                $this->error('  ❌ ping threw: ' . $e->getMessage());
            }
        }

        $this->line('');
        return self::SUCCESS;
    }
}
