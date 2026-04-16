<?php

namespace App\Http\Controllers;

use App\Models\AgentShare;
use App\Agents\AgentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AgentShareController extends Controller
{
    // ── ADMIN: List all shares ───────────────────────────────────────────────
    public function index()
    {
        $shares = AgentShare::where('created_by', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('agent-shares.index', [
            'shares'    => $shares,
            'agentMeta' => AgentShare::agentMeta(),
        ]);
    }

    // ── ADMIN: Create new share ──────────────────────────────────────────────
    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_key'        => 'required|string|max:50',
            'client_name'      => 'required|string|max:100',
            'client_email'     => 'nullable|email|max:150',
            'password'         => 'nullable|string|min:4|max:100',
            'custom_title'     => 'nullable|string|max:100',
            'welcome_message'  => 'nullable|string|max:500',
            'show_branding'    => 'nullable|boolean',
            'allow_sap_access' => 'nullable|boolean',
            'expires_at'       => 'nullable|date|after:now',
        ]);

        $share = AgentShare::create([
            'token'            => AgentShare::generateToken(),
            'agent_key'        => $data['agent_key'],
            'client_name'      => $data['client_name'],
            'client_email'     => $data['client_email'] ?? null,
            'password_hash'    => isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
            'custom_title'     => $data['custom_title'] ?? null,
            'welcome_message'  => $data['welcome_message'] ?? null,
            'show_branding'    => $request->boolean('show_branding', true),
            'allow_sap_access' => $request->boolean('allow_sap_access', false),
            'expires_at'       => $data['expires_at'] ?? null,
            'created_by'       => auth()->id(),
        ]);

        return response()->json([
            'ok'  => true,
            'url' => $share->getUrl(),
            'id'  => $share->id,
        ]);
    }

    // ── ADMIN: Toggle active ─────────────────────────────────────────────────
    public function toggle(AgentShare $share)
    {
        $this->authorize_owner($share);
        $share->update(['is_active' => !$share->is_active]);
        return response()->json(['ok' => true, 'is_active' => $share->is_active]);
    }

    // ── ADMIN: Delete ────────────────────────────────────────────────────────
    public function destroy(AgentShare $share)
    {
        $this->authorize_owner($share);
        $share->delete();
        return response()->json(['ok' => true]);
    }

    // ── PUBLIC: Show shared agent chat page ──────────────────────────────────
    public function show(string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->isValid()) {
            return view('agent-shares.expired');
        }

        // Password check — if set and not yet verified in session
        if ($share->password_hash) {
            $sessionKey = 'share_auth_' . $share->token;
            if (!session($sessionKey)) {
                return view('agent-shares.password', ['token' => $token]);
            }
        }

        $meta = AgentShare::agentMeta()[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900'];

        return view('agent-shares.chat', [
            'share' => $share,
            'meta'  => $meta,
        ]);
    }

    // ── PUBLIC: Password verification ────────────────────────────────────────
    public function verifyPassword(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->password_hash) {
            return redirect('/a/' . $token);
        }

        $password = $request->input('password', '');
        if ($share->checkPassword($password)) {
            session(['share_auth_' . $token => true]);
            return redirect('/a/' . $token);
        }

        return back()->withErrors(['password' => 'Palavra-passe incorrecta.']);
    }

    // ── PUBLIC: SSE Stream for shared agent ──────────────────────────────────
    public function stream(Request $request, string $token)
    {
        $share = AgentShare::where('token', $token)->firstOrFail();

        if (!$share->isValid()) {
            return response()->json(['error' => 'Link expirado ou desactivado.'], 403);
        }

        // Password check
        if ($share->password_hash) {
            $sessionKey = 'share_auth_' . $share->token;
            if (!session($sessionKey)) {
                return response()->json(['error' => 'Autenticação necessária.'], 401);
            }
        }

        $message   = $request->input('message', '');
        $history   = $request->input('history', []);
        $sessionId = $request->input('session_id', 'shared_' . uniqid());

        // File/image attachments — support both FormData (multipart) and JSON (base64)
        $imageB64  = $request->input('image');
        $imageType = $request->input('image_type', 'image/jpeg');
        $fileB64   = $request->input('file_b64');
        $fileType  = $request->input('file_type', 'application/octet-stream');
        $fileName  = $request->input('file_name', 'ficheiro');

        // FormData uploads: image_blob or file_upload (UploadedFile objects)
        if (!$imageB64 && $request->hasFile('image_blob')) {
            $uploaded  = $request->file('image_blob');
            $imageB64  = base64_encode(file_get_contents($uploaded->getRealPath()));
            $imageType = $request->input('image_type', $uploaded->getMimeType() ?: 'image/jpeg');
        }
        if (!$fileB64 && $request->hasFile('file_upload')) {
            $uploaded = $request->file('file_upload');
            $fileB64  = base64_encode(file_get_contents($uploaded->getRealPath()));
            $fileType = $request->input('file_type', $uploaded->getMimeType() ?: 'application/octet-stream');
            $fileName = $request->input('file_name', $uploaded->getClientOriginalName() ?: 'ficheiro');
        }

        // Multiple files via FormData files[] — detect early so we can check empty message
        $uploadedFiles = $request->file('files', []);

        if (empty(trim($message)) && !$imageB64 && !$fileB64 && empty($uploadedFiles)) {
            return response()->json(['error' => 'Mensagem vazia.'], 422);
        }

        // Sanitize history
        $history = collect($history)
            ->filter(fn($m) => isset($m['role'], $m['content']))
            ->map(fn($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->toArray();

        // Build multimodal message if file/image present
        if ($imageB64) {
            $message = [
                ['type' => 'text',  'text' => $message ?: 'O que vês nesta imagem?'],
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => $imageType,
                    'data'       => $imageB64,
                ]],
            ];
        } elseif ($fileB64 && preg_match('/pdf/i', $fileType . $fileName)) {
            $message = [
                ['type' => 'text',     'text'   => $message],
                ['type' => 'document', 'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $fileB64,
                ]],
            ];
        } elseif ($fileB64 && preg_match('/spreadsheet|excel|\.xlsx|\.xls/i', $fileType . $fileName)) {
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            $message .= $this->extractExcelText($tmp, $fileName);
        } elseif ($fileB64 && preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $fileType . $fileName)) {
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            $message .= $this->extractWordText($tmp, $fileName);
        } elseif ($fileB64) {
            // Unsupported binary — note only
            $message .= "\n\n[Ficheiro: {$fileName} — formato não suportado para análise de texto]";
        }

        // Multiple files via FormData files[]
        if (!empty($uploadedFiles) && !$imageB64) {
            $pdfBlocks  = [];
            $textAppend = '';

            foreach ($uploadedFiles as $uploadedFile) {
                $fname = $uploadedFile->getClientOriginalName();
                $ftype = $uploadedFile->getMimeType() ?: 'application/octet-stream';
                $fpath = $uploadedFile->getRealPath();

                if (preg_match('/pdf/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file PDF [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $pdfBlocks[] = [
                        'type'   => 'document',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => 'application/pdf',
                            'data'       => base64_encode(file_get_contents($fpath)),
                        ],
                    ];
                } elseif (preg_match('/spreadsheet|excel|\.xlsx|\.xls/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file Excel [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $textAppend .= $this->extractExcelText($fpath, $fname);
                } elseif (preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $ftype . $fname)) {
                    Log::info("AgentShare: multi-file Word [{$fname}] " . round(filesize($fpath) / 1024) . ' KB');
                    $textAppend .= $this->extractWordText($fpath, $fname);
                } else {
                    $content = @file_get_contents($fpath);
                    if ($content) {
                        $textAppend .= "\n\n---\n**{$fname}**\n```\n" . substr($content, 0, 8000) . "\n```";
                    }
                }
            }

            if (!empty($pdfBlocks)) {
                $baseText = is_array($message) ? ($message[0]['text'] ?? '') : $message;
                $contentBlocks = [['type' => 'text', 'text' => $baseText . $textAppend]];
                foreach ($pdfBlocks as $block) {
                    $contentBlocks[] = $block;
                }
                $message = $contentBlocks;
            } elseif ($textAppend !== '') {
                if (is_array($message)) {
                    $message[0]['text'] = ($message[0]['text'] ?? '') . $textAppend;
                } else {
                    $message .= $textAppend;
                }
            }
        }

        // SAP access control — set runtime flag so agents respect the share's permission
        if (!$share->allow_sap_access) {
            config(['app.sap_access_blocked' => true]);
        }

        $agentManager = app(AgentManager::class);
        $agent        = $agentManager->agent($share->agent_key);
        $agentName    = $agent->getName();
        $agentModel   = $agent->getModel();

        // Record usage
        $share->recordUsage();

        return response()->stream(function () use ($agent, $message, $history, $agentName, $agentModel, $sessionId) {
            while (ob_get_level() > 0) { ob_end_flush(); }
            flush();

            $meta = [
                'type'       => 'meta',
                'mode'       => 'single',
                'agent'      => $agentName,
                'model'      => $agentModel,
                'session_id' => $sessionId,
            ];
            echo 'data: ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            flush();

            $heartbeat = function (string $status = '') {
                echo ': heartbeat' . ($status ? " {$status}" : '') . "\n\n";
                flush();
            };

            try {
                // Sanitise all text to valid UTF-8 before Guzzle json-encodes the request
                $safeMessage = $this->sanitizeForApi($message);
                $safeHistory = array_map(fn($m) => $this->sanitizeForApi($m), $history);

                $agent->stream(
                    $safeMessage,
                    $safeHistory,
                    function (string $chunk) {
                        echo 'data: ' . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                        flush();
                    },
                    $heartbeat
                );
            } catch (\Throwable $e) {
                Log::error('AgentShare stream error', ['token' => request()->route('token'), 'error' => $e->getMessage()]);
                echo 'data: ' . json_encode(['error' => 'Erro ao processar. Tenta novamente.'], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
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

    // ── File parsing helpers ─────────────────────────────────────────────────

    /**
     * Extract text content from an Excel XLSX file (no external library required).
     * Deletes the temp file when done.
     */
    private function extractExcelText(string $path, string $name): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $sharedStrings = [];
                $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                if ($ssXml) {
                    $ssXml = preg_replace('/<r>.*?<\/r>/s', '', $ssXml);
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $m);
                    $sharedStrings = array_map(
                        fn($v) => $this->safeUtf8(html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8')),
                        $m[1]
                    );
                }
                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
                $zip->close();
                $lines = [];
                preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rows);
                foreach ($rows[1] as $rowXml) {
                    $cells = [];
                    preg_match_all('/<c r="([A-Z]+)\d+"([^>]*)>(.*?)<\/c>/s', $rowXml, $allCells, PREG_SET_ORDER);
                    foreach ($allCells as $cell) {
                        $isStr = str_contains($cell[2], 't="s"');
                        preg_match('/<v>([^<]*)<\/v>/', $cell[3], $vMatch);
                        $val = $vMatch[1] ?? '';
                        if ($isStr) $val = $sharedStrings[(int)$val] ?? $val;
                        $cells[] = $this->safeUtf8((string)$val);
                    }
                    if (array_filter($cells, fn($c) => $c !== '')) {
                        $lines[] = implode(' | ', $cells);
                    }
                }
                $text = implode("\n", $lines);
                return "\n\n---\n**Ficheiro Excel: {$name}**\n```\n" . substr(trim($text), 0, 15000) . "\n```";
            } else {
                return "\n\n[Excel: não foi possível abrir o ficheiro]";
            }
        } catch (\Throwable $e) {
            Log::warning("AgentShare Excel parse failed ({$name}): " . $e->getMessage());
            return "\n\n[Excel: erro ao processar — " . $e->getMessage() . "]";
        } finally {
            @unlink($path);
        }
    }

    /**
     * Extract text content from a Word DOCX file (no external library required).
     * Deletes the temp file when done.
     */
    private function extractWordText(string $path, string $name): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $xml = $zip->getFromName('word/document.xml') ?: '';
                $zip->close();
                $xml  = str_replace(['</w:p>', '</w:tr>', '<w:br/>'], ["\n", "\n", "\n"], $xml);
                $text = strip_tags($xml);
                $text = preg_replace('/[ \t]+/', ' ', $text);
                $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                $text = $this->safeUtf8($text);
                return "\n\n---\n**Ficheiro Word: {$name}**\n" . substr($text, 0, 15000);
            } else {
                return "\n\n[Word: não foi possível abrir o ficheiro]";
            }
        } catch (\Throwable $e) {
            Log::warning("AgentShare Word parse failed ({$name}): " . $e->getMessage());
            return "\n\n[Word: erro ao processar — " . $e->getMessage() . "]";
        } finally {
            @unlink($path);
        }
    }

    // ── UTF-8 sanitizers ─────────────────────────────────────────────────────
    private function safeUtf8(string $s): string
    {
        $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $out ?? $s);
        return $out !== false ? $out : '';
    }

    private function sanitizeForApi(mixed $value): mixed
    {
        if (is_string($value)) return $this->safeUtf8($value);
        if (is_array($value))  return array_map(fn($v) => $this->sanitizeForApi($v), $value);
        return $value;
    }

    // ── Helper ───────────────────────────────────────────────────────────────
    private function authorize_owner(AgentShare $share): void
    {
        if ($share->created_by !== auth()->id() && !auth()->user()->isAdmin()) {
            abort(403);
        }
    }
}
