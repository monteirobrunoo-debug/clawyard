<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderServiceAnalysis extends Model
{
    protected $fillable = [
        'tender_id', 'status',
        'agents_consulted', 'sections', 'executive_summary',
        'total_cost_usd',
        'generated_by_user_id', 'generated_at',
    ];

    protected $casts = [
        'agents_consulted' => 'array',
        'sections'         => 'array',
        'total_cost_usd'   => 'decimal:4',
        'generated_at'     => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function isFresh(int $hours = 24): bool
    {
        return $this->generated_at && $this->generated_at->diffInHours(now()) < $hours;
    }

    /**
     * Achata todas as recommendations dos vários agentes numa to-do list
     * limpa e deduplicada. Para usar no PDF, no UI checklist, e como
     * conteúdo das notas que sincronizam para o SAP Opportunity Remarks.
     *
     * Estrutura devolvida:
     *   [
     *     ['agent_key' => 'finance', 'agent_name' => 'Dr. Luís', 'emoji' => '💰', 'text' => '...'],
     *     ...
     *   ]
     *
     * Dedup por texto exacto (case-insensitive). Mantém a ordem original
     * (insertion order) — útil quando há acordo entre agentes sobre o
     * próximo passo prioritário.
     */
    public function extractActionItems(): array
    {
        $sections = (array) ($this->sections ?? []);
        $items = [];
        $seen  = [];
        foreach ($sections as $agentKey => $sec) {
            $recs = (array) ($sec['recommendations'] ?? []);
            foreach ($recs as $rec) {
                $text = trim((string) $rec);
                if ($text === '') continue;
                $norm = mb_strtolower($text);
                if (isset($seen[$norm])) continue;
                $seen[$norm] = true;
                $items[] = [
                    'agent_key'  => $agentKey,
                    'agent_name' => $sec['agent_name']  ?? $agentKey,
                    'emoji'      => $sec['agent_emoji'] ?? '🤖',
                    'text'       => $text,
                ];
            }
        }
        return $items;
    }

    /**
     * Converte a action-list para um bloco de texto plain pronto para
     * meter em tender.notes → sincronizado para SAP Opportunity Remarks
     * (cap 254 chars do SAP B1).
     *
     * Formato:
     *   [Análise Multi-Agente · 18/05/2026]
     *   • passo um · finance
     *   • passo dois · acingov
     *   ...
     *
     * Se o total exceder 240 chars (deixar margem para o cabeçalho do
     * SAP), corta os menos prioritários e adiciona "(+N mais)".
     */
    public function toSapNotesBlock(?int $maxChars = 240): string
    {
        $items = $this->extractActionItems();
        if (empty($items)) return '';

        $date = $this->generated_at?->format('d/m/Y') ?? now()->format('d/m/Y');
        $header = "[Análise Multi-Agente · {$date}]\n";

        $lines = [];
        foreach ($items as $it) {
            $lines[] = '• ' . $it['text'] . ' · ' . $it['agent_key'];
        }
        $body = implode("\n", $lines);
        $full = $header . $body;

        if ($maxChars && mb_strlen($full) > $maxChars) {
            // Corta linhas do fim até caber. Adiciona contador do que ficou de fora.
            while (count($lines) > 1 && mb_strlen($header . implode("\n", $lines) . "\n• (+? mais)") > $maxChars) {
                array_pop($lines);
            }
            $cut = count($items) - count($lines);
            if ($cut > 0) {
                $lines[] = "(+{$cut} mais — ver PDF)";
            }
            $full = $header . implode("\n", $lines);
        }
        return $full;
    }
}
