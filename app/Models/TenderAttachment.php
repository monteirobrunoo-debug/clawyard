<?php

namespace App\Models;

use App\Casts\SafeEncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderAttachment extends Model
{
    protected $fillable = [
        'tender_id',
        'original_name', 'disk_path', 'mime_type', 'size_bytes', 'file_hash',
        'extracted_text', 'extracted_chars', 'extraction_status', 'extraction_error',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes'      => 'integer',
        'extracted_chars' => 'integer',
        // AES-encrypted at rest via APP_KEY. Same pattern as Message.content.
        // Existing plaintext rows return SafeEncryptedString::PLACEHOLDER on
        // decrypt failure until the backfill migration re-encrypts them.
        'extracted_text'  => SafeEncryptedString::class,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    /** 2026-05-18: ficheiro não-PDF guardado sem extracção (xlsx, eml, jpg, …). */
    public const STATUS_SKIPPED = 'skipped';

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Returns the text in a form safe to inject into a prompt — capped, trimmed. */
    public function promptSnippet(int $maxChars = 6000): string
    {
        $text = (string) $this->extracted_text;
        if (mb_strlen($text) <= $maxChars) return $text;
        return mb_substr($text, 0, $maxChars) . "\n\n…[truncado a {$maxChars} caracteres]";
    }

    /**
     * Extrai a secção "Statement of Requirements" (ou equivalente PT) do
     * texto do anexo — peça mais crítica num RFP NATO/NSPA.
     *
     * 2026-05-18: pedido directo do operador — "falta a info que está no
     * Statement of Requirements, muito importante". Esta secção contém
     * as specs técnicas linha-a-linha que o fornecedor precisa para cotar.
     *
     * Heurística:
     *   1. Procura cabeçalhos comuns ("Statement of Requirements",
     *      "Technical Requirements", "Requisitos Técnicos", "SoR",
     *      "Specifications", "Especificações", "Annex A", "Anexo A").
     *   2. Extrai do cabeçalho até ao próximo cabeçalho/secção ou fim do
     *      texto (cap maxChars).
     *   3. Se não encontrar cabeçalho, devolve null (caller decide se usa
     *      o texto inteiro como fallback).
     *
     * Útil para Daniel emails, martaSummarize, e Inquiry PartYard PDF.
     */
    public function extractStatementOfRequirements(int $maxChars = 10000): ?string
    {
        $text = (string) $this->extracted_text;
        if ($text === '') return null;

        // Cabeçalhos comuns de SoR em PT/EN, ordenados por especificidade.
        // O padrão captura desde o cabeçalho até ao próximo cabeçalho ou
        // fim do texto (lookahead).
        $headers = [
            'statement\s+of\s+requirements?',
            'technical\s+requirements?',
            'technical\s+specifications?',
            'specifications?\s+of\s+supply',
            'requisitos\s+t[ée]cnicos',
            'especifica[çc][õo]es?\s+t[ée]cnicas?',
            'caderno\s+de\s+encargos',
            'annex\s+a\b',
            'anexo\s+a\b',
            'scope\s+of\s+(supply|work)',
            '\bso[rR]\b',
            'lista\s+de\s+equipamentos?',
            'items?\s+to\s+(supply|quote|deliver)',
            'list\s+of\s+items',
            'lots?\s+description',
        ];

        $headerRe = '/(?:^|\n)\s*(?:\d+[\.\)]\s*)?(?:' . implode('|', $headers) . ')\b/iu';

        if (!preg_match($headerRe, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startByte = $m[0][1];
        // Converter byte offset para char offset (UTF-8 safe).
        $start = mb_strlen(substr($text, 0, $startByte));

        // Próximo cabeçalho de secção (limita o tamanho do SoR).
        // Padrões: "N. UPPERCASE", "Section N", "Annex B+", "Chapter N"
        $endRe = '/\n\s*(?:\d+\.\s+[A-ZÁÉÍÓÚ][A-ZÁÉÍÓÚ\s]{4,}|Section\s+\d+|Annex\s+[B-Z]|Anexo\s+[B-Z]|Chapter\s+\d+)/u';
        $remainder = mb_substr($text, $start);
        $sor = $remainder;

        if (preg_match($endRe, $remainder, $em, PREG_OFFSET_CAPTURE)) {
            $endByte = $em[0][1];
            $endChar = mb_strlen(substr($remainder, 0, $endByte));
            $sor = mb_substr($remainder, 0, $endChar);
        }

        $sor = trim($sor);
        if (mb_strlen($sor) > $maxChars) {
            $sor = mb_substr($sor, 0, $maxChars) . "\n…[SoR truncado em {$maxChars} chars]";
        }
        return $sor !== '' ? $sor : null;
    }
}
