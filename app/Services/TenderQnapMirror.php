<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TenderQnapMirror — espelho dos anexos do tender dashboard para a árvore
 * QNAP, replicando a convenção que a equipa já usa manualmente:
 *
 *   /var/www/qnapbackup/PartYard_{YYYY}_SAP/CLIENTES/{SOURCE}/
 *     {SAP_OPP_NUM} - {REFERENCE} - {TITLE}/
 *       {original_name}.pdf
 *       ...
 *
 * 2026-05-19 — pedido directo do operador:
 *   "quando envio pdf para analisar no dashboard de concursos, ele ter
 *    maneira de criar pasta no nosso servidor interno… cria um passo
 *    para gravar logo de uma vez no server qnap"
 *
 * Comportamento:
 *   • Idempotente — não duplica se o ficheiro já lá está com o mesmo hash
 *   • Best-effort — falha (mount unavailable, permission) é LOG.warning,
 *     não bloqueia upload no dashboard
 *   • Reusa o filesystem mount montado em /var/www/qnapbackup (o mesmo que
 *     o QnapAgent já lê para fazer index)
 *   • Cria a estrutura de pastas on-demand (mkdir -p)
 *
 * Quando NÃO espelhar:
 *   • Tenders confidenciais (is_confidential = true) — fica só em local/private
 *   • Quando QNAP_MIRROR_ENABLED=false no env (kill switch)
 */
class TenderQnapMirror
{
    /**
     * Base path do QNAP mount no servidor de produção. Configurável via
     * env QNAP_BACKUP_PATH para ambientes diferentes (dev sem QNAP).
     */
    public function basePath(): string
    {
        return rtrim((string) config('services.qnap.base_path',
            env('QNAP_BACKUP_PATH', '/var/www/qnapbackup')), '/');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.qnap.mirror_enabled',
            filter_var(env('QNAP_MIRROR_ENABLED', false), FILTER_VALIDATE_BOOLEAN))
            && is_dir($this->basePath())
            && is_writable($this->basePath());
    }

    /**
     * Constrói o caminho relativo da pasta do tender DENTRO do QNAP base.
     * Convenção alinhada com a equipa:
     *   PartYard_{YYYY}_SAP/CLIENTES/{SOURCE}/{SAP_OPP_NUM} - {REFERENCE} - {TITLE}
     *
     * Year = ano do created_at do tender (não o ano corrente).
     * Source = NSPA/NATO/SAM_GOV/ACINGOV/etc. (upper).
     * SAP_OPP_NUM omitido se ainda não houver Opp criada → usa "PYD-NNNNNN".
     */
    public function tenderFolder(Tender $tender): string
    {
        $year   = $tender->created_at?->format('Y') ?? now()->format('Y');
        $source = strtoupper(trim((string) ($tender->source ?? 'OUTROS')));
        if ($source === '') $source = 'OUTROS';

        $oppNum = trim((string) ($tender->sap_opportunity_number ?? ''));
        if ($oppNum === '') $oppNum = 'PYD-' . str_pad((string) $tender->id, 6, '0', STR_PAD_LEFT);

        $ref = trim((string) ($tender->reference ?? ''));
        $title = trim((string) ($tender->title ?? 'tender'));

        // Filesystem-safe — remove / : * ? " < > | etc. Mantém - _ , . ()
        $safeRef   = $this->safeName($ref);
        $safeTitle = $this->safeName($title);

        $folderParts = [$oppNum];
        if ($safeRef   !== '') $folderParts[] = $safeRef;
        if ($safeTitle !== '') $folderParts[] = $safeTitle;
        $folder = implode(' - ', $folderParts);
        // Hard cap 180 chars no nome da pasta (algumas SMB shares cortam a 220)
        $folder = mb_substr($folder, 0, 180);

        return "PartYard_{$year}_SAP/CLIENTES/{$source}/{$folder}";
    }

    /**
     * Espelha UM TenderAttachment para o QNAP. Idempotente — compara hash
     * antes de re-copiar.
     *
     * @return string|null  Caminho absoluto no QNAP onde o ficheiro ficou,
     *                      ou null se foi pulado (disabled/confidential/error).
     */
    public function mirrorAttachment(TenderAttachment $att): ?string
    {
        if (!$this->isEnabled()) return null;

        $tender = $att->tender;
        if (!$tender) return null;
        if ($tender->is_confidential) {
            // Concurso marcado como confidencial — não sai do servidor app
            return null;
        }

        try {
            $relFolder = $this->tenderFolder($tender);
            $absFolder = $this->basePath() . '/' . $relFolder;

            if (!is_dir($absFolder)) {
                @mkdir($absFolder, 0775, true);
                if (!is_dir($absFolder)) {
                    Log::warning('TenderQnapMirror: mkdir failed', [
                        'tender_id' => $tender->id,
                        'folder'    => $absFolder,
                    ]);
                    return null;
                }
                // Cria um README curto na pasta na 1ª criação (ajuda a equipa
                // a perceber porque a pasta existe se nunca foi tocada via QNAP)
                @file_put_contents(
                    $absFolder . '/README.txt',
                    "Pasta criada automaticamente por ClawYard.\n" .
                    "Tender ID: {$tender->id}\n" .
                    "Source: " . ($tender->source ?? '?') . "\n" .
                    "Reference: " . ($tender->reference ?? '?') . "\n" .
                    "Created: " . ($tender->created_at?->format('Y-m-d H:i') ?? '?') . "\n" .
                    "URL: " . url('/tenders/' . $tender->id) . "\n"
                );
            }

            $destName = $this->safeName($att->original_name ?: ('att-' . $att->id));
            $destAbs  = $absFolder . '/' . $destName;

            // Idempotente: se já existe e tem o mesmo hash, não re-copia
            if (file_exists($destAbs)) {
                $existingHash = @hash_file('sha256', $destAbs);
                if ($existingHash && $existingHash === $att->file_hash) {
                    return $destAbs;
                }
                // Hash diferente — versionar adicionando suffix com data
                $destAbs = $absFolder . '/' . pathinfo($destName, PATHINFO_FILENAME)
                         . '-' . now()->format('Ymd-Hi')
                         . '.' . pathinfo($destName, PATHINFO_EXTENSION);
            }

            // Copia bytes do disco local → QNAP
            $sourceAbs = Storage::disk('local')->path($att->disk_path);
            if (!is_readable($sourceAbs)) {
                Log::warning('TenderQnapMirror: source unreadable', [
                    'attachment_id' => $att->id,
                    'source'        => $sourceAbs,
                ]);
                return null;
            }
            if (!@copy($sourceAbs, $destAbs)) {
                Log::warning('TenderQnapMirror: copy failed', [
                    'attachment_id' => $att->id,
                    'dest'          => $destAbs,
                ]);
                return null;
            }
            return $destAbs;
        } catch (\Throwable $e) {
            Log::warning('TenderQnapMirror: exception — ' . $e->getMessage(), [
                'tender_id'     => $tender?->id,
                'attachment_id' => $att->id,
            ]);
            return null;
        }
    }

    /**
     * Espelha todos os anexos de um tender (útil ao criar SAP Opp — agarra
     * todos os PDFs já existentes e empurra para a pasta nova com o
     * sap_opportunity_number no nome).
     */
    public function mirrorAll(Tender $tender): array
    {
        $out = ['mirrored' => 0, 'skipped' => 0, 'paths' => []];
        if (!$this->isEnabled()) return $out;
        $tender->load('attachments');
        foreach ($tender->attachments as $att) {
            $p = $this->mirrorAttachment($att);
            if ($p) {
                $out['mirrored']++;
                $out['paths'][] = $p;
            } else {
                $out['skipped']++;
            }
        }
        return $out;
    }

    /**
     * Sanitiza um nome de ficheiro/pasta para SMB/NTFS/EXT4:
     *   • Remove: / \\ : * ? " < > |
     *   • Substitui múltiplos espaços por um só
     *   • Trim leading/trailing espaços e pontos
     */
    private function safeName(string $s): string
    {
        $s = preg_replace('#[/\\\\:*?"<>|]+#', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s, " .\t\n\r\0\x0B");
    }
}
