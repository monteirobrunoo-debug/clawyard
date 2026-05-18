<?php

namespace App\Services;

use App\Models\AgentShare;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AgentShareFileStore — espelho filesystem de cada agent share.
 *
 * 2026-05-18 — pedido directo do operador:
 *   "para cada agente partilhado cria uma pasta no servidor para não ser
 *    só browser porque depois desaparece a info"
 *
 * Estrutura de pastas (em storage/app/, disco "local"):
 *
 *   agent-shares/
 *     {share_id}/
 *       index.json                  ← metadata do share + lista de conversas
 *       conversations/
 *         default.jsonl             ← append-log da conversa "default"
 *         c-abc123.jsonl            ← append-log de outras conversas
 *         ...
 *
 * Cada linha do .jsonl é uma mensagem completa:
 *   {"role":"user","content":"...","ts":"2026-05-18T18:36:28Z","conv":"default"}
 *
 * Propriedades:
 *   • PERSISTENTE — sobrevive a limpeza de cookies / mudança de browser
 *   • AUDITÁVEL — admin do share pode descarregar a pasta inteira via FTP/SFTP
 *   • BACKUPABLE — incluído nos snapshots cron do storage/app
 *   • FAIL-OPEN — falhas de escrita são LOG.warning, não bloqueiam o chat
 *   • COMPLEMENTAR — BD Postgres continua a ser source of truth para a UI;
 *     o filesystem é mirror redundante
 *
 * O conteúdo é PLAINTEXT (em contraste com Postgres que tem
 * SafeEncryptedString). Esta escolha é deliberada — a pasta vive em disco
 * controlado pela H&P; encriptação extra adicionaria complexidade sem
 * ganho de segurança (filesystem do servidor já tem ACL do user "forge").
 */
class AgentShareFileStore
{
    private const BASE_DIR = 'agent-shares';

    /**
     * Devolve o path relativo da pasta de um share (e cria-a se ainda
     * não existir). Idempotente — chamável em cada turn sem custo.
     */
    public function ensureShareDir(AgentShare $share): string
    {
        $dir = self::BASE_DIR . '/' . $share->id;
        try {
            Storage::disk('local')->makeDirectory($dir);
            Storage::disk('local')->makeDirectory($dir . '/conversations');
            $this->updateIndex($share);
        } catch (\Throwable $e) {
            Log::info('AgentShareFileStore: ensureShareDir failed — ' . $e->getMessage(), [
                'share_id' => $share->id,
            ]);
        }
        return $dir;
    }

    /**
     * Appendar UM turn ao .jsonl da conversa correspondente.
     *
     * @param AgentShare $share
     * @param string $convSlug  '' (ou 'default') para a conversa default;
     *                          slug "c-abc123" para multi-conv
     * @param array  $turn      ['role' => 'user'|'assistant', 'content' => '...']
     */
    public function appendTurn(AgentShare $share, string $convSlug, array $turn): void
    {
        try {
            $dir = $this->ensureShareDir($share);
            $convName = $this->safeName($convSlug !== '' ? $convSlug : 'default');
            $file = $dir . '/conversations/' . $convName . '.jsonl';

            $line = json_encode([
                'role'    => $turn['role'] ?? 'user',
                'content' => (string) ($turn['content'] ?? ''),
                'ts'      => now()->toIso8601String(),
                'conv'    => $convName,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

            // Append atómico — usa o filesystem driver directamente para
            // não substituir o ficheiro inteiro a cada turn (Storage::put
            // sobrescreve; só "append" suporta concat).
            $disk = Storage::disk('local');
            if (method_exists($disk, 'append')) {
                $disk->append($file, rtrim($line, "\n"));
            } else {
                // Fallback portável: read + concat + put (subóptimo mas seguro)
                $existing = $disk->exists($file) ? $disk->get($file) : '';
                $disk->put($file, $existing . $line);
            }

            $this->updateIndex($share);
        } catch (\Throwable $e) {
            Log::info('AgentShareFileStore: appendTurn failed — ' . $e->getMessage(), [
                'share_id' => $share->id,
                'conv'     => $convSlug,
            ]);
        }
    }

    /**
     * Lê todas as mensagens de uma conversa (file mirror). Usado como
     * fallback se a BD estiver indisponível ou para auditoria.
     *
     * @return list<array{role:string,content:string,ts:string}>
     */
    public function readConversation(AgentShare $share, string $convSlug): array
    {
        try {
            $dir = self::BASE_DIR . '/' . $share->id;
            $convName = $this->safeName($convSlug !== '' ? $convSlug : 'default');
            $file = $dir . '/conversations/' . $convName . '.jsonl';
            if (!Storage::disk('local')->exists($file)) return [];

            $content = Storage::disk('local')->get($file);
            $out = [];
            foreach (preg_split('/\r?\n/', (string) $content) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $obj = json_decode($line, true);
                if (is_array($obj) && isset($obj['role'], $obj['content'])) {
                    $out[] = $obj;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Lista conversas existentes no filesystem para este share.
     * Mais robusto que a BD para shares com muitas conversas órfãs.
     *
     * @return list<array{slug:string, file:string, size:int, modified:int}>
     */
    public function listConversations(AgentShare $share): array
    {
        try {
            $dir = self::BASE_DIR . '/' . $share->id . '/conversations';
            if (!Storage::disk('local')->exists($dir)) return [];
            $files = Storage::disk('local')->files($dir);
            $out = [];
            foreach ($files as $file) {
                if (!str_ends_with($file, '.jsonl')) continue;
                $name = basename($file, '.jsonl');
                $out[] = [
                    'slug'     => $name === 'default' ? '' : $name,
                    'file'     => $file,
                    'size'     => Storage::disk('local')->size($file),
                    'modified' => Storage::disk('local')->lastModified($file),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Refresh do index.json — metadata do share + sumário de conversas.
     * Chamado em cada appendTurn (cheap O(N) onde N = nº de conversas).
     */
    private function updateIndex(AgentShare $share): void
    {
        try {
            $dir = self::BASE_DIR . '/' . $share->id;
            $convs = $this->listConversations($share);

            $index = [
                'share_id'        => $share->id,
                'token'           => $share->token,
                'agent_key'       => $share->agent_key,
                'client_name'     => $share->client_name,
                'client_email'    => $share->client_email,
                'created_by'      => $share->created_by,
                'created_at'      => $share->created_at?->toIso8601String(),
                'usage_count'     => $share->usage_count,
                'last_updated_at' => now()->toIso8601String(),
                'conversations'   => array_map(fn($c) => [
                    'slug'         => $c['slug'],
                    'file'         => $c['file'],
                    'size_bytes'   => $c['size'],
                    'modified_at'  => date('c', $c['modified']),
                ], $convs),
            ];

            Storage::disk('local')->put(
                $dir . '/index.json',
                json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // Index updates são best-effort; falha não bloqueia o chat
        }
    }

    private function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $name) ?: 'default';
    }
}
