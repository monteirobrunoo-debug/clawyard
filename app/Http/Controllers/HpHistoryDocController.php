<?php

namespace App\Http\Controllers;

use App\Services\HpHistoryClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated proxy for hp-history `/doc/{id}` endpoint.
 *
 * Why a proxy instead of letting the chat UI hit hp-history directly:
 *   • The HMAC shared secret never leaves the Laravel server. Browser
 *     can't sign a request, and we don't want to ship the secret to
 *     the front-end.
 *   • The same /chat session auth (web middleware + verified email)
 *     already gates access to the conversation that produced the
 *     citation. Reusing it for the document fetch is the right
 *     security boundary.
 *   • Keeping the URL short and on the same origin (`/hp-history/doc/
 *     {uuid}`) lets us render plain `<a href>` in the bubble.
 *
 * Streamed response so a 50MB PDF doesn't load fully into PHP memory.
 */
class HpHistoryDocController extends Controller
{
    public function show(Request $request, HpHistoryClient $client, string $docId)
    {
        // Accept the citation_url shape too in case the agent prompt
        // happened to render `/doc/<uuid>` as the link target.
        $clean = ltrim($docId, '/');
        if (str_starts_with($clean, 'doc/')) {
            $clean = substr($clean, 4);
        }

        $tuple = $client->fetchDocument($clean);

        if (!$tuple) {
            // Could be: hp-history disabled, doc not found, doc not in
            // managed library, network blip. Return a friendly 404
            // page instead of leaking which.
            Log::info('hp-history doc proxy: nothing to serve', [
                'doc'     => $clean,
                'user_id' => $request->user()?->id,
            ]);
            abort(404, 'Documento não disponível.');
        }

        [$stream, $contentType, $headers] = $tuple;

        // Audit who downloaded what — useful when historical content
        // is sensitive (we proxy NDAs, customer contracts, etc.).
        Log::info('hp-history doc served', [
            'doc'      => $clean,
            'user_id'  => $request->user()?->id,
            'mime'     => $contentType,
            'qnap_src' => $headers['X-HP-Doc-Source'] ?? null,
        ]);

        return new StreamedResponse(function () use ($stream) {
            // Read in 8KB chunks so the connection can be closed
            // promptly if the client aborts.
            while (!$stream->eof()) {
                echo $stream->read(8192);
                if (function_exists('ob_flush')) @ob_flush();
                @flush();
            }
        }, 200, [
            'Content-Type'         => $contentType,
            'Content-Disposition'  => $headers['Content-Disposition'],
            // Don't cache in shared proxies — these are user-scoped
            // documents. Browser can keep its own copy.
            'Cache-Control'        => 'private, max-age=300',
            // Don't leak the QNAP/SharePoint origin URL to the client.
            // It's still in the Laravel log for audit.
        ]);
    }
}
