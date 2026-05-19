<?php

namespace App\Console\Commands;

use App\Models\TechnicalBookChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Ingere 1 ficheiro EPUB em chunks na tabela `technical_book_chunks`.
 *
 * EPUB = ZIP com XHTML/HTML. O command:
 *   1. Abre o zip
 *   2. Lê o OPF (manifest) para encontrar os ficheiros do conteúdo
 *   3. Strip de tags HTML, mantém parágrafos
 *   4. Concatena tudo e split em chunks ~2500 chars com boundary em
 *      newlines (evita cortar frases a meio)
 *   5. Escreve cada chunk em technical_book_chunks com book_key,
 *      book_title, domain, page_no (= chunk index) e keywords vazio
 *      (FTS index trata da busca textual)
 *
 * Embeddings são gerados depois com `php artisan books:embed`.
 *
 * Usage:
 *   php artisan books:ingest-epub /path/file.epub --domain=negotiation
 *   php artisan books:ingest-epub /path/file.epub --domain=negotiation --title="Never Split the Difference" --replace
 */
class IngestEpubBookCommand extends Command
{
    protected $signature = 'books:ingest-epub
                            {path : Caminho absoluto para o .epub}
                            {--domain=outros : Domínio (ex.: negotiation, strategy, marketing)}
                            {--title= : Override do título (auto-derivado do filename se omitido)}
                            {--key= : Override do book_key (auto-derivado se omitido)}
                            {--chunk-size=2500 : Tamanho aproximado dos chunks em chars}
                            {--replace : Apagar chunks anteriores com o mesmo book_key antes de ingerir}';

    protected $description = 'Ingere 1 ficheiro .epub em chunks na knowledge base dos agentes';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("Ficheiro não existe: {$path}");
            return self::FAILURE;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'epub') {
            $this->error('Esperava .epub; recebi: ' . pathinfo($path, PATHINFO_EXTENSION));
            return self::FAILURE;
        }

        $domain    = (string) $this->option('domain');
        $chunkSize = max(500, (int) $this->option('chunk-size'));

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $bookKey  = (string) ($this->option('key') ?: $this->slugify($filename));
        $bookKey  = mb_substr($bookKey, 0, 80);

        $bookTitle = (string) ($this->option('title') ?: $this->prettify($filename));

        if ($this->option('replace')) {
            $deleted = TechnicalBookChunk::where('book_key', $bookKey)->delete();
            $this->warn("🗑  Apagados {$deleted} chunks anteriores de '{$bookKey}'");
        } else {
            $existing = TechnicalBookChunk::where('book_key', $bookKey)->count();
            if ($existing > 0) {
                $this->warn("⚠  Já existem {$existing} chunks com book_key='{$bookKey}'. Usa --replace ou --key=... para forçar.");
                return self::FAILURE;
            }
        }

        $this->info("📖 Ingestão: {$bookTitle}");
        $this->line("   book_key={$bookKey} · domain={$domain} · chunk≈{$chunkSize} chars");

        $text = $this->extractEpubText($path);
        if (mb_strlen($text) < 500) {
            $this->error('Texto extraído é demasiado curto (' . mb_strlen($text) . ' chars). EPUB inválido ou só com imagens?');
            return self::FAILURE;
        }

        $this->info('📝 ' . number_format(mb_strlen($text)) . ' chars de texto · a dividir em chunks…');

        $chunks = $this->chunkText($text, $chunkSize);
        $this->info('   ' . count($chunks) . ' chunks gerados.');

        $inserted = 0;
        DB::transaction(function () use ($chunks, $bookKey, $bookTitle, $domain, &$inserted) {
            foreach ($chunks as $i => $chunk) {
                TechnicalBookChunk::create([
                    'book_key'   => $bookKey,
                    'book_title' => $bookTitle,
                    'domain'     => $domain,
                    'page_no'    => $i + 1,
                    'content'    => $chunk,
                    'keywords'   => null,
                ]);
                $inserted++;
            }
        });

        $this->info("✅ Inseridos {$inserted} chunks em technical_book_chunks.");
        $this->line('');
        $this->line('Próximo passo (opcional, para semantic search):');
        $this->line('  php artisan books:embed --book-key=' . $bookKey);

        return self::SUCCESS;
    }

    /** Extrai todo o texto útil de um ficheiro EPUB. */
    private function extractEpubText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Não consegui abrir o EPUB como ZIP.');
        }

        // Encontrar OPF (manifest). EPUB tem META-INF/container.xml a
        // apontar para o OPF. Se não conseguirmos, fallback para
        // varrer todos os xhtml/html do zip.
        $opfHref = null;
        $containerXml = $zip->getFromName('META-INF/container.xml');
        if ($containerXml !== false) {
            if (preg_match('/full-path=[\'\"]([^\'\"]+)[\'\"]/', $containerXml, $m)) {
                $opfHref = $m[1];
            }
        }

        $contentFiles = [];
        if ($opfHref) {
            $opfDir = trim(dirname($opfHref), '.');
            $opfXml = $zip->getFromName($opfHref);
            if ($opfXml !== false && preg_match_all(
                '/<item[^>]+href=[\'\"]([^\'\"]+)[\'\"][^>]+media-type=[\'\"]application\/xhtml\+xml[\'\"]/i',
                $opfXml,
                $matches
            )) {
                foreach ($matches[1] as $rel) {
                    $contentFiles[] = $opfDir !== '' ? "{$opfDir}/{$rel}" : $rel;
                }
            }
        }

        // Fallback: varrer todos os .xhtml/.html do zip
        if (empty($contentFiles)) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/\.(xhtml|html|htm)$/i', (string) $name)) {
                    $contentFiles[] = $name;
                }
            }
        }

        $textParts = [];
        foreach ($contentFiles as $f) {
            $html = $zip->getFromName($f);
            if ($html === false || $html === '') continue;
            $textParts[] = $this->htmlToText($html);
        }
        $zip->close();

        return trim(implode("\n\n", $textParts));
    }

    /** Limpa HTML/XHTML mantendo só texto + newlines entre parágrafos. */
    private function htmlToText(string $html): string
    {
        // Remover scripts/styles e tags vazias
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        // Substituir <br> e block-level por newlines antes de strip_tags
        $html = preg_replace('/<\/?(p|div|h[1-6]|li|br|tr)[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Colapsar whitespace mas preservar parágrafos
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Divide o texto em chunks de ~ $targetSize chars cortando em
     * fronteiras de newline para não partir frases.
     *
     * @return list<string>
     */
    private function chunkText(string $text, int $targetSize): array
    {
        $chunks = [];
        $paragraphs = preg_split('/\n+/', $text) ?: [];

        $buffer = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') continue;

            if (mb_strlen($buffer) + mb_strlen($p) + 1 > $targetSize && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }
            $buffer = $buffer === '' ? $p : ($buffer . "\n" . $p);
        }
        if ($buffer !== '') $chunks[] = $buffer;

        return $chunks;
    }

    private function slugify(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/u', '-', $s) ?? $s;
        $s = trim($s, '-');
        return mb_strtolower($s);
    }

    private function prettify(string $s): string
    {
        $s = str_replace(['_', '-'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim(ucwords($s));
    }
}
