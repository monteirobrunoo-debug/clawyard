<?php

namespace App\Console\Commands;

use App\Models\TechnicalBookChunk;
use Illuminate\Console\Command;

/**
 * Apaga chunks da biblioteca técnica com OCR pobre / texto inutilizável.
 * PDFs scaneados (sobretudo livros mais antigos) deixam páginas com
 * texto tipo "/  !X" ou cadeias de caracteres não-imprimíveis que
 * poluem os embeddings sem dar valor à pesquisa.
 *
 * Critério "lixo":
 *   • >50% chars não-alfanuméricos (excl. espaços e pontuação básica)
 *   • OU < 30 palavras com ≥3 chars
 *
 * Idempotente. Após correr, recomenda-se:
 *   php artisan books:embed --refresh
 *   (regenerar embeddings sem os chunks lixo no índice — IVFFlat
 *    funciona melhor com vectors de qualidade homogénea)
 *
 * Usage:
 *   php artisan books:cleanup --dry-run    (mostra o que apagaria)
 *   php artisan books:cleanup              (apaga)
 *   php artisan books:cleanup --threshold=0.6  (mais permissivo)
 */
class CleanupTechnicalBooksCommand extends Command
{
    protected $signature = 'books:cleanup
                            {--dry-run : mostra o que iria apagar sem mexer na DB}
                            {--threshold=0.5 : máximo de chars não-alfanuméricos permitido (0-1)}';

    protected $description = 'Apaga chunks com OCR pobre / texto não-utilizável';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $threshold = max(0.1, min(0.9, (float) $this->option('threshold')));

        $this->info("🧹 Cleanup com threshold={$threshold} · dry-run=" . ($dryRun ? 'yes' : 'no'));

        $total   = TechnicalBookChunk::count();
        $killed  = 0;
        $kept    = 0;
        $samples = [];

        TechnicalBookChunk::orderBy('id')->chunkById(200, function ($chunks) use ($threshold, $dryRun, &$killed, &$kept, &$samples) {
            foreach ($chunks as $c) {
                $isJunk = $this->isLowQuality((string) $c->content, $threshold);
                if ($isJunk) {
                    $killed++;
                    if (count($samples) < 3) {
                        $samples[] = [
                            'id'    => $c->id,
                            'book'  => $c->book_title,
                            'page'  => $c->page_no,
                            'first' => mb_substr(preg_replace('/\s+/', ' ', $c->content) ?? '', 0, 100),
                        ];
                    }
                    if (!$dryRun) $c->delete();
                } else {
                    $kept++;
                }
            }
        });

        $this->info('');
        $this->info("📊 Total: {$total} · A apagar: {$killed} · Manter: {$kept}");

        if (!empty($samples)) {
            $this->info('');
            $this->info('Exemplos do que ' . ($dryRun ? 'iria apagar' : 'foi apagado') . ':');
            foreach ($samples as $s) {
                $this->line("  · #{$s['id']} {$s['book']} p.{$s['page']}");
                $this->line("    > " . mb_substr($s['first'], 0, 100));
            }
        }

        if (!$dryRun && $killed > 0) {
            $this->info('');
            $this->warn('💡 Recomenda-se correr: php artisan books:embed --refresh');
            $this->warn('   (re-indexa para o IVFFlat optimizar sem os chunks lixo)');
        }

        return self::SUCCESS;
    }

    /**
     * Heurística de qualidade. Devolve true se o chunk parece OCR
     * lixo / texto corrompido / página sem extracção útil.
     */
    private function isLowQuality(string $text, float $threshold): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 50) return true;

        // Critério 1: rácio de chars não-alfanum (excluindo whitespace e
        // pontuação básica). PDFs com OCR pobre têm muito '!@#$%^&*'.
        $stripped = preg_replace('/[\p{L}\p{N}\s.,;:!?\-()\[\]"\'\/°ºª%]/u', '', $text);
        $junkRatio = mb_strlen($stripped) / max(1, mb_strlen($text));
        if ($junkRatio > $threshold) return true;

        // Critério 2: nº de palavras "reais" (≥3 chars alfanum).
        // Páginas válidas em livros técnicos têm ≥30 palavras reais
        // (ex: índice tem ≥40, parágrafo de teoria tem ≥80).
        if (preg_match_all('/\p{L}{3,}/u', $text) < 30) return true;

        return false;
    }
}
