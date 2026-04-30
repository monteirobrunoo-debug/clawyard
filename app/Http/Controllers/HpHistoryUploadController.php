<?php

namespace App\Http\Controllers;

use App\Services\HpHistoryClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Drag-drop upload UI that feeds historical proposals into the
 * hp-history droplet without anyone having to scp+SSH.
 *
 * Why a proxy instead of letting the browser hit hp-history directly:
 *   • The HMAC shared secret never leaves the Laravel server. The
 *     browser can't sign the request.
 *   • Manager+ gate matches the rest of the SAP/tender admin surface —
 *     historical proposals can contain confidential pricing.
 *   • One round-trip: browser → Laravel → hp-history. The Laravel
 *     server reads the file binary, base64-encodes into JSON, and
 *     calls HpHistoryClient::uploadFiles().
 *
 * Limits (matched to the FastAPI side in services/hp-history/app/main.py):
 *   • 10 files per submission
 *   • 16 MB per file (after base64 the payload is ~21 MB)
 *   • .pdf, .txt, .md only
 */
class HpHistoryUploadController extends Controller
{
    /** Max files per submission — must mirror /ingest/upload server. */
    private const MAX_FILES = 10;

    /** Max single file size in bytes — must mirror /ingest/upload server. */
    private const MAX_BYTES = 16 * 1024 * 1024;

    public function index(HpHistoryClient $client)
    {
        $this->authorizeManager();

        return view('hp-history.upload', [
            'enabled'  => $client->isEnabled(),
            'maxFiles' => self::MAX_FILES,
            'maxMb'    => 16,
        ]);
    }

    public function store(Request $request, HpHistoryClient $client)
    {
        $this->authorizeManager();

        if (!$client->isEnabled()) {
            return back()->withErrors([
                'files' => 'O serviço hp-history está desligado (HP_HISTORY_ENABLED=false). Liga-o primeiro no .env do droplet.',
            ]);
        }

        $request->validate([
            'files'        => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'files.*'      => ['file', 'max:' . (self::MAX_BYTES / 1024), 'mimes:pdf,txt,md'],
            'domain'       => ['nullable', Rule::in(['spares', 'marine', 'military'])],
            'year'         => ['nullable', 'integer', 'min:1990', 'max:2100'],
        ], [
            'files.required' => 'Escolhe pelo menos um ficheiro.',
            'files.max'      => 'Máximo ' . self::MAX_FILES . ' ficheiros por submissão.',
            'files.*.max'    => 'Cada ficheiro tem de ter ≤ 16 MB.',
            'files.*.mimes'  => 'Só aceitamos PDF, TXT ou MD.',
        ]);

        $payloadFiles = [];
        foreach ($request->file('files', []) as $f) {
            if (!$f || !$f->isValid()) continue;
            $payloadFiles[] = [
                'name'   => $f->getClientOriginalName(),
                'binary' => file_get_contents($f->getRealPath()),
            ];
        }

        if (empty($payloadFiles)) {
            return back()->withErrors(['files' => 'Não foi possível ler os ficheiros submetidos.']);
        }

        $year   = $request->filled('year')   ? (int) $request->input('year')   : null;
        $domain = $request->filled('domain') ? (string) $request->input('domain') : null;

        $result = $client->uploadFiles(
            files: $payloadFiles,
            domain: $domain,
            year: $year,
            metadata: [
                'uploader'  => Auth::user()?->email ?? 'unknown',
                'source'    => 'clawyard-ui',
                'tenant_id' => Auth::user()?->id,
            ],
        );

        Log::info('hp-history upload via clawyard UI', [
            'user'   => Auth::user()?->email,
            'count'  => count($payloadFiles),
            'domain' => $domain,
            'year'   => $year,
            'result' => array_intersect_key(
                $result,
                array_flip(['ok', 'docs_ingested', 'chunks_ingested', 'skipped', 'error'])
            ),
        ]);

        if (!($result['ok'] ?? false)) {
            return back()->withErrors([
                'files' => 'hp-history rejeitou o upload: ' . ($result['error'] ?? 'unknown'),
            ]);
        }

        $docs    = (int) ($result['docs_ingested']   ?? 0);
        $chunks  = (int) ($result['chunks_ingested'] ?? 0);
        $skipped = (array) ($result['skipped']       ?? []);

        $msg = "✓ {$docs} documento(s) ingerido(s), {$chunks} chunk(s) indexado(s).";
        if ($skipped) {
            $msg .= ' Saltados: ' . implode(', ', array_slice($skipped, 0, 5));
            if (count($skipped) > 5) $msg .= ' (+' . (count($skipped) - 5) . ' outros)';
        }

        return back()->with('status', $msg);
    }

    private function authorizeManager(): void
    {
        $u = Auth::user();
        if (!$u || !$u->isManager()) abort(403, 'Apenas managers podem alimentar a base histórica.');
    }
}
