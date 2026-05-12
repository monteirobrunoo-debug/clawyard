<?php

namespace App\Console\Commands;

use App\Models\TechnicalBookChunk;
use Illuminate\Console\Command;
use Smalot\PdfParser\Parser;
use Symfony\Component\Finder\Finder;

/**
 * Reads PDFs from the technical library directory, extracts text
 * page-by-page (smalot/pdfparser), and stores one row per page in
 * `technical_book_chunks` for keyword citation in WorkReportAgent.
 *
 * Usage:
 *   php artisan books:ingest /path/to/biblioteca-tecnica
 *   php artisan books:ingest --domain=soldadura /path/to/soldadura/
 *   php artisan books:ingest --refresh   (truncates table first)
 *
 * Director ystructure expected:
 *   biblioteca-tecnica/
 *     soldadura/01-metalurgia-da-soldagem-modenesi-marques-santos.pdf
 *     naval/arquitectura-naval-cacho.pdf
 *     ...
 *
 * The domain is inferred from the parent directory name. Falls back
 * to "outros" for files outside soldadura/naval.
 */
class IngestTechnicalBooksCommand extends Command
{
    protected $signature = 'books:ingest
                            {path : Caminho para a biblioteca-tecnica/}
                            {--domain= : forçar domain (soldadura|naval|outros)}
                            {--refresh : truncar tabela antes de ingerir}
                            {--use-pdftotext : usar Poppler/pdftotext em vez de smalot (mais tolerante para PDFs corruptos ou >100MB)}
                            {--pdftotext-fallback : tenta smalot primeiro; se falhar, cai para pdftotext}';

    protected $description = 'Ingere PDFs técnicos (soldadura/naval) em chunks por página';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_dir($path)) {
            $this->error("Directório não existe: {$path}");
            return self::FAILURE;
        }

        if ($this->option('refresh')) {
            TechnicalBookChunk::truncate();
            $this->warn('🗑  Tabela truncada (--refresh)');
        }

        $finder = (new Finder())->files()->in($path)->name('*.pdf');
        $this->info('📚 Ingestão de ' . $finder->count() . ' livros...');

        $totalChunks = 0;
        $totalBooks  = 0;
        $errors      = 0;

        foreach ($finder as $file) {
            $bookKey = preg_replace('/\.pdf$/i', '', $file->getFilename());
            $bookKey = preg_replace('/[^A-Za-z0-9_\-]/', '-', $bookKey);
            $bookKey = mb_substr($bookKey, 0, 80);

            $domain = $this->option('domain');
            if (!$domain) {
                $parent = $file->getRelativePath();
                if (str_contains($parent, 'soldadura')) $domain = 'soldadura';
                elseif (str_contains($parent, 'naval'))  $domain = 'naval';
                else $domain = 'outros';
            }

            $bookTitle = ucwords(str_replace(['-', '_'], ' ', preg_replace('/^\d+-/', '', $bookKey)));

            $usePopplerFirst = (bool) $this->option('use-pdftotext');
            $popplerFallback = (bool) $this->option('pdftotext-fallback');

            try {
                if ($usePopplerFirst) {
                    $pages = $this->extractWithPdftotext($file->getRealPath());
                    $engine = 'pdftotext';
                } else {
                    try {
                        $parser = new Parser();
                        $pdf    = $parser->parseFile($file->getRealPath());
                        $pages  = array_map(
                            fn($p) => trim((string) $p->getText()),
                            $pdf->getPages()
                        );
                        $engine = 'smalot';
                    } catch (\Throwable $e) {
                        if (!$popplerFallback) throw $e;
                        $this->warn("  · {$file->getFilename()} — smalot falhou ({$e->getMessage()}), a tentar pdftotext…");
                        $pages = $this->extractWithPdftotext($file->getRealPath());
                        $engine = 'pdftotext (fallback)';
                    }
                }

                if (empty($pages)) {
                    $this->warn("  · {$file->getFilename()} — sem texto extraído (PDF imagem?)");
                    continue;
                }

                $bar = $this->output->createProgressBar(count($pages));
                $bar->start();

                foreach ($pages as $i => $pageText) {
                    $clean = trim((string) $pageText);
                    if (mb_strlen($clean) < 50) { $bar->advance(); continue; }
                    $clean = mb_substr($clean, 0, 8000);

                    TechnicalBookChunk::updateOrCreate(
                        ['book_key' => $bookKey, 'page_no' => $i + 1],
                        [
                            'book_title' => $bookTitle,
                            'domain'     => $domain,
                            'content'    => $clean,
                            'keywords'   => $this->extractKeywords($clean),
                        ]
                    );
                    $totalChunks++;
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
                $totalBooks++;
                $this->info("  ✓ {$file->getFilename()} — " . count($pages) . " páginas [{$engine}]");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ✗ {$file->getFilename()} — " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("📊 Ingestão completa: {$totalBooks} livros · {$totalChunks} chunks · {$errors} erros");
        return self::SUCCESS;
    }

    /**
     * Extracção via Poppler/pdftotext (binário de sistema).
     *
     * Razões para existir como alternativa ao smalot/pdfparser:
     *   • Smalot mantém todo o object graph do PDF em memória →
     *     PDFs >100MB rebentam mesmo com memory_limit=2G.
     *   • Smalot é estrito com PDFs malformados (xref partido,
     *     streams inválidos, encriptação trivial) → erros como
     *     "Trying to access array offset on null".
     *   • Poppler streams page-by-page, ignora erros menores, e
     *     suporta PDFs com encriptação owner-only (sem password).
     *
     * Pages são separadas por \f (form feed) no output do
     * pdftotext, que é o standard.
     *
     * @return array<int, string>  index 0-based = página 1
     */
    private function extractWithPdftotext(string $absPath): array
    {
        $tmp  = tempnam(sys_get_temp_dir(), 'pdftotext_');
        $cmd  = sprintf(
            '/usr/bin/pdftotext -layout -enc UTF-8 -nopgbrk %s %s 2>&1',
            escapeshellarg($absPath),
            escapeshellarg($tmp)
        );
        // Note: -nopgbrk would strip form-feed; we WANT \f as page sep,
        // so we re-issue without it:
        $cmd  = sprintf(
            '/usr/bin/pdftotext -layout -enc UTF-8 %s %s 2>&1',
            escapeshellarg($absPath),
            escapeshellarg($tmp)
        );
        $out  = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            @unlink($tmp);
            throw new \RuntimeException(
                'pdftotext exit ' . $code . ': ' . implode(' | ', array_slice($out, 0, 3))
            );
        }
        $raw = @file_get_contents($tmp) ?: '';
        @unlink($tmp);

        if ($raw === '') return [];

        // Split por form-feed (page break standard do Poppler)
        $pages = explode("\x0c", $raw);
        // Remove última página vazia (\f no fim do ficheiro)
        if (end($pages) === '' || trim((string) end($pages)) === '') {
            array_pop($pages);
        }
        return array_values($pages);
    }

    /**
     * Extrai keywords técnicas de uma página (matérias e processos
     * comuns em soldadura naval e reparação naval). Lista hardcoded
     * é intencional — termos técnicos têm pouca variação e o
     * benefício de keyword-level matching supera o custo dum
     * tokenizer mais sofisticado.
     */
    private function extractKeywords(string $text): array
    {
        $needles = [
            // Processos
            'mma','smaw','tig','gtaw','mig','mag','gmaw','fcaw','saw','plasma',
            // Eléctrodos comuns
            'e6013','e7018','e7016','e308l','er70s','er316l',
            // Materiais
            'aço','aco','steel','inox','stainless','aluminio','alumínio',
            'cobre','copper','duplex','super duplex',
            // Inspecção/NDT
            'ndt','utm','ut','rt','mt','pt','vt','ultrasónico','ultrasonico',
            'magnetic particle','penetrant',
            // Mecânica/reparação
            'bomba','pump','válvula','valve','rolamento','bearing',
            'veio','shaft','propulsor','propeller','helice','hélice',
            'gearbox','redutor','engine','motor','mtu','caterpillar','wartsila',
            // Naval / classificação
            'casco','hull','convés','convés','imo','solas','dnv','lloyd',
            'class society','classification',
            // Soldadura específica
            'wps','pqr','aws','iso 15614','asme ix','preheat','pwht',
            'pré-aquecimento','pos-soldadura',
        ];
        $found = [];
        $lower = mb_strtolower($text);
        foreach ($needles as $kw) {
            if (str_contains($lower, $kw)) $found[] = $kw;
        }
        return array_values(array_unique($found));
    }
}
