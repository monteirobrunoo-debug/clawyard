<?php

namespace App\Http\Controllers;

use App\Agents\BriefingAgent;
use App\Models\Report;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BriefingController extends Controller
{
    /** GET /briefing */
    public function index()
    {
        // Check if a briefing was already generated today
        $todayBriefing = Report::where('type', 'briefing')
            ->where('created_at', '>=', now()->startOfDay())
            ->latest()
            ->first();

        return view('briefing.index', compact('todayBriefing'));
    }

    /** GET /briefing/stream — SSE stream that generates the briefing */
    public function stream(Request $request): StreamedResponse
    {
        set_time_limit(300);
        $userId = auth()->id();

        return response()->stream(function () use ($userId) {

            // ── 1. Flush ALL PHP output buffers immediately (same as NvidiaController)
            while (ob_get_level() > 0) ob_end_flush();
            flush();

            // ── 2. Release session lock — allows other requests to proceed during stream
            session()->save();

            // ── 3. Send meta event so the frontend knows the stream is alive
            echo 'data: ' . json_encode(['type' => 'meta', 'mode' => 'briefing']) . "\n\n";
            flush();

            // ── 4. Heartbeat — keep Nginx / Cloudflare alive (no ob_flush needed)
            $heartbeat = function (string $status = '') {
                echo ': heartbeat' . ($status ? " {$status}" : '') . "\n\n";
                flush();
            };

            $agent = new BriefingAgent();
            $full  = '';

            try {
                $full = $agent->stream(
                    'Generate daily briefing',
                    [],
                    function (string $chunk) {
                        echo 'data: ' . json_encode(['chunk' => $chunk]) . "\n\n";
                        flush();
                    },
                    $heartbeat
                );
            } catch (\Throwable $e) {
                \Log::error('BriefingAgent error: ' . $e->getMessage());
                echo 'data: ' . json_encode(['error' => 'Erro ao gerar briefing: ' . $e->getMessage()]) . "\n\n";
                flush();
                echo "data: [DONE]\n\n";
                flush();
                return;
            }

            // Auto-save as report
            if (strlen($full) > 200) {
                try {
                    Report::create([
                        'title'   => '📊 Briefing Executivo — ' . now()->format('d/m/Y'),
                        'type'    => 'briefing',
                        'user_id' => $userId,
                        'content' => $full,
                        'summary' => substr(strip_tags($full), 0, 300),
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Could not save briefing report: ' . $e->getMessage());
                }
            }

            echo "data: [DONE]\n\n";
            flush();

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /** GET /briefing/pdf/{report} */
    public function pdf(Report $report)
    {
        abort_unless($report->type === 'briefing', 404);
        return view('briefing.pdf', compact('report'));
    }

    /** GET /briefing/latest/pdf — PDF of latest briefing */
    public function latestPdf()
    {
        $report = Report::where('type', 'briefing')->latest()->firstOrFail();
        return view('briefing.pdf', compact('report'));
    }
}
