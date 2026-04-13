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
            'agent_key'       => 'required|string|max:50',
            'client_name'     => 'required|string|max:100',
            'client_email'    => 'nullable|email|max:150',
            'password'        => 'nullable|string|min:4|max:100',
            'custom_title'    => 'nullable|string|max:100',
            'welcome_message' => 'nullable|string|max:500',
            'show_branding'   => 'nullable|boolean',
            'expires_at'      => 'nullable|date|after:now',
        ]);

        $share = AgentShare::create([
            'token'           => AgentShare::generateToken(),
            'agent_key'       => $data['agent_key'],
            'client_name'     => $data['client_name'],
            'client_email'    => $data['client_email'] ?? null,
            'password_hash'   => isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
            'custom_title'    => $data['custom_title'] ?? null,
            'welcome_message' => $data['welcome_message'] ?? null,
            'show_branding'   => $request->boolean('show_branding', true),
            'expires_at'      => $data['expires_at'] ?? null,
            'created_by'      => auth()->id(),
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

        // File/image attachments
        $imageB64  = $request->input('image');
        $imageType = $request->input('image_type', 'image/jpeg');
        $fileB64   = $request->input('file_b64');
        $fileType  = $request->input('file_type', 'application/octet-stream');
        $fileName  = $request->input('file_name', 'ficheiro');

        if (empty(trim($message)) && !$imageB64 && !$fileB64) {
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
            // Excel XLSX — extract text from ZIP XML (same logic as main chat)
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            try {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    $sharedStrings = [];
                    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                    if ($ssXml) {
                        $ssXml = preg_replace('/<r>.*?<\/r>/s', '', $ssXml);
                        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $m);
                        $sharedStrings = array_map('html_entity_decode', $m[1]);
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
                            $cells[] = $val;
                        }
                        if (array_filter($cells, fn($c) => $c !== '')) {
                            $lines[] = implode(' | ', $cells);
                        }
                    }
                    $text = implode("\n", $lines);
                    $message .= "\n\n---\n**Ficheiro Excel: {$fileName}**\n```\n" . substr(trim($text), 0, 15000) . "\n```";
                } else {
                    $message .= "\n\n[Excel: não foi possível abrir o ficheiro]";
                }
            } catch (\Throwable $e) {
                $message .= "\n\n[Excel: erro ao processar — " . $e->getMessage() . "]";
            } finally {
                @unlink($tmp);
            }
        } elseif ($fileB64 && preg_match('/wordprocessing|msword|\.docx|\.doc$/i', $fileType . $fileName)) {
            // Word DOCX — extract text from ZIP XML
            $tmp = tempnam(sys_get_temp_dir(), 'shr_');
            file_put_contents($tmp, base64_decode($fileB64));
            try {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    $xml = $zip->getFromName('word/document.xml') ?: '';
                    $zip->close();
                    $xml  = str_replace(['</w:p>', '</w:tr>', '<w:br/>'], ["\n", "\n", "\n"], $xml);
                    $text = strip_tags($xml);
                    $text = preg_replace('/[ \t]+/', ' ', $text);
                    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                    $message .= "\n\n---\n**Ficheiro Word: {$fileName}**\n" . substr($text, 0, 15000);
                } else {
                    $message .= "\n\n[Word: não foi possível abrir o ficheiro]";
                }
            } catch (\Throwable $e) {
                $message .= "\n\n[Word: erro ao processar — " . $e->getMessage() . "]";
            } finally {
                @unlink($tmp);
            }
        } elseif ($fileB64) {
            // Unsupported binary — note only
            $message .= "\n\n[Ficheiro: {$fileName} — formato não suportado para análise de texto]";
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
                $agent->stream(
                    $message,
                    $history,
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

    // ── Helper ───────────────────────────────────────────────────────────────
    private function authorize_owner(AgentShare $share): void
    {
        if ($share->created_by !== auth()->id() && !auth()->user()->isAdmin()) {
            abort(403);
        }
    }
}
