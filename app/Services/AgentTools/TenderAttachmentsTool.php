<?php

namespace App\Services\AgentTools;

use App\Models\TenderAttachment;

/**
 * tender_attachments — lê extracted_text de PDFs/imagens anexos a um tender.
 *
 * Caso de uso: o agente já viu o snippet inicial no system prompt mas
 * quer "ler o PDF completo" para extrair specs específicas, items do SoR,
 * NSN, P/N, etc. Permite escolher qual attachment (por nome ou id) ou
 * fazer match parcial.
 *
 * Cap: 12000 chars por execução (caber em 1 turn sem rebentar context).
 */
class TenderAttachmentsTool implements AgentToolInterface
{
    public function name(): string { return 'tender_attachments_read'; }

    public function description(): string
    {
        return 'Lê o texto extraído de um anexo (PDF/imagem) do tender actual. '
             . 'Útil quando o snippet inicial não chegou e precisas de ler '
             . 'specs técnicas, items do SoR, NSN/P-N, condições contratuais. '
             . 'Devolve até 12.000 chars do anexo.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_name_contains' => [
                    'type'        => 'string',
                    'description' => 'Match parcial no nome do anexo (ex: "SoR", "Annex A"). Vazio = primeiro anexo OK.',
                ],
                'start_offset' => [
                    'type'        => 'integer',
                    'description' => 'Offset em chars onde começar a ler (default 0). Útil para paginar PDFs grandes.',
                    'minimum'     => 0,
                ],
            ],
        ];
    }

    public function execute(array $input, array $context): array
    {
        $tenderId = (int) ($context['tender_id'] ?? 0);
        if (!$tenderId) {
            return ['ok' => false, 'error' => 'tender_id em falta no contexto'];
        }

        $needle = trim((string) ($input['attachment_name_contains'] ?? ''));
        $offset = max(0, (int) ($input['start_offset'] ?? 0));

        $q = TenderAttachment::where('tender_id', $tenderId)
            ->where('extraction_status', TenderAttachment::STATUS_OK);
        if ($needle !== '') {
            $q->where('original_name', 'ILIKE', '%' . str_replace('%', '\%', $needle) . '%');
        }

        $att = $q->orderBy('id')->first();
        if (!$att) {
            return [
                'ok' => false,
                'error' => $needle !== ''
                    ? "Nenhum anexo OK com nome contendo '{$needle}'."
                    : 'Tender sem anexos com texto extraído.',
            ];
        }

        $full   = (string) $att->extracted_text;
        $total  = mb_strlen($full);
        $chunk  = mb_substr($full, $offset, 12000);
        $more   = ($offset + mb_strlen($chunk)) < $total;

        $header = sprintf(
            "Anexo: %s (%d chars total · a ler chars %d–%d)\n",
            $att->original_name,
            $total,
            $offset,
            $offset + mb_strlen($chunk),
        );
        if ($more) {
            $header .= "[NOTA: anexo tem mais conteúdo. Volta a chamar com start_offset="
                    . ($offset + mb_strlen($chunk)) . " para ver o resto.]\n";
        }
        return ['ok' => true, 'result' => $header . "\n" . $chunk];
    }
}
