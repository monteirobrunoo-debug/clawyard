<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Conversation extends Model
{
    protected $fillable = [
        'session_id', 'channel', 'agent', 'phone', 'email', 'name', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    // ── Sliding window config ────────────────────────────────────────────────
    // Keep the last N full messages; summarise everything older in one block.
    const RECENT_KEEP   = 20;  // messages to pass verbatim (10 turns)
    const SUMMARY_AFTER = 30;  // trigger summarisation when total > this

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // ── History for API calls ─────────────────────────────────────────────────
    public function getHistoryAttribute(): array
    {
        $total = $this->messages()->count();

        // Fast path — short conversation, pass everything
        if ($total <= self::SUMMARY_AFTER) {
            return $this->messages()
                ->orderBy('created_at', 'desc')
                ->limit(40)
                ->get()
                ->reverse()
                ->values()
                ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
                ->toArray();
        }

        // Sliding window path — summarise older messages, keep recent verbatim
        $recentMessages = $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit(self::RECENT_KEEP)
            ->get()
            ->reverse()
            ->values();

        $oldestRecentId = $recentMessages->first()?->id ?? 0;

        // Build / refresh summary of older messages
        $summary = $this->getOrBuildSummary($oldestRecentId);

        $history = [];

        if ($summary) {
            // Inject summary as first user+assistant exchange so Claude has context
            $history[] = ['role' => 'user',      'content' => 'Resumo da conversa anterior:'];
            $history[] = ['role' => 'assistant',  'content' => $summary];
        }

        foreach ($recentMessages as $m) {
            $history[] = ['role' => $m->role, 'content' => $m->content];
        }

        return $history;
    }

    // ── Build or retrieve cached summary of older messages ───────────────────
    protected function getOrBuildSummary(int $beforeMessageId): ?string
    {
        $meta          = $this->metadata ?? [];
        $cachedSummary = $meta['summary'] ?? null;
        $cachedBefore  = $meta['summary_before_id'] ?? 0;

        // Re-use cached summary if it already covers up to the same message boundary
        if ($cachedSummary && $cachedBefore === $beforeMessageId) {
            return $cachedSummary;
        }

        // Collect older messages to summarise
        $older = $this->messages()
            ->where('id', '<', $beforeMessageId)
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => ucfirst($m->role) . ': ' . substr($m->content, 0, 400))
            ->implode("\n");

        if (!$older) return null;

        // Ask Claude to produce a compact summary (non-streaming, fire-and-forget style)
        try {
            $apiKey = config('services.anthropic.api_key');
            if (!$apiKey) return $cachedSummary; // graceful degradation

            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'timeout'  => 30,
            ]);

            $resp = $client->post('/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => config('services.anthropic.model', 'claude-haiku-4-5'),
                    'max_tokens' => 400,
                    'system'     => 'You are a conversation summariser. Given a conversation excerpt, produce a concise summary in Portuguese (max 300 words) that captures the key topics, decisions, and context. Respond ONLY with the summary text.',
                    'messages'   => [
                        ['role' => 'user', 'content' => "Summarise this conversation:\n\n{$older}"],
                    ],
                ],
            ]);

            $data    = json_decode($resp->getBody()->getContents(), true);
            $summary = $data['content'][0]['text'] ?? null;

            if ($summary) {
                // Cache in conversation metadata
                $meta['summary']           = $summary;
                $meta['summary_before_id'] = $beforeMessageId;
                $this->update(['metadata' => $meta]);
            }

            return $summary;
        } catch (\Throwable $e) {
            Log::warning('Conversation summary failed: ' . $e->getMessage());
            return $cachedSummary; // return stale cache on failure
        }
    }
}
