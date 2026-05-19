<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * PartYardMilitaryWordTemplate — molde MOD_072_V3 reutilizável para
 * geradores .docx INTERNOS do ClawYard.
 *
 * 2026-05-19 — pedido directo do operador:
 *   "Usar os modelos da PartYard Military, quando exportas em word…
 *    todos os agentes, menos os partilhados usam este modelo"
 *
 * Aplica o branding do Sistema de Gestão de Qualidade da PartYard Military
 * (MODELOS/PY Military_(em vigor)/Inquiry_MILITARY_MOD_072_V3.docx):
 *   • Section header com banner Partyard military division + contactos
 *   • Section footer com NCAGE P3527 + NATO + ISO 9001 + H&P GROUP
 *   • Audit-line MOD_072_V3 para traceability SGQ
 *
 * USO:
 *   $phpWord = new PhpWord();
 *   $section = $phpWord->addSection(
 *       PartYardMilitaryWordTemplate::sectionConfig()
 *   );
 *   PartYardMilitaryWordTemplate::apply($section, [
 *       'subtitle'  => 'Work Report — Job #1234',
 *       'audit_ref' => 'WR-' . $tender->id,
 *   ]);
 *   // ...adicionar conteúdo do documento...
 *
 * AGENTES PARTILHADOS NÃO USAM:
 * O agent share (Dr. Ana / Dloren / clientes externos) NÃO deve invocar
 * este helper — quando se partilha um agente com cliente externo, o
 * documento NÃO deve carregar o branding PartYard Defense porque pode
 * confundir / vazar a relação interna H&P Group → cliente. Para clientes
 * externos usar layout neutro (ver views/agent-shares/conversation-pdf).
 */
class PartYardMilitaryWordTemplate
{
    /** Filename do template original .docx (SGQ MOD_072_V3). */
    public const TEMPLATE_DOCX = 'Inquiry_MILITARY_MOD_072_V3.docx';

    /**
     * Path do directório com os 3 assets do template (header.jpg,
     * footer.png, defense-banner.png) + o próprio .docx original.
     */
    public static function assetsDir(): string
    {
        return resource_path('templates/inquiry-military');
    }

    /**
     * Path absoluto do .docx original do SGQ. Pedido directo do operador
     * (2026-05-19): "O ficheiro inquiry militar tem de ser este". Em vez
     * de reconstruir o layout via PhpWord, usamos LITERALMENTE este .docx
     * como base e apenas anexamos no fim a parte técnica (items table +
     * compliance matrix). Garante 100% de fidelidade visual ao MOD_072_V3
     * porque é o próprio ficheiro do SGQ.
     */
    public static function templateDocxPath(): string
    {
        return self::assetsDir() . '/' . self::TEMPLATE_DOCX;
    }

    /**
     * True se o .docx original está acessível no servidor. Quando true,
     * os geradores devem preferir o approach "load template + append"
     * em vez de reconstruir layout via PhpWord do zero.
     */
    public static function hasOriginalTemplate(): bool
    {
        return is_readable(self::templateDocxPath());
    }

    /**
     * Verifica se os assets do template estão disponíveis no servidor.
     * Em ambientes dev sem o volume HP Group montado pode não ter, então
     * os geradores fazem fallback gracioso para o layout textual antigo.
     */
    public static function isAvailable(): bool
    {
        $dir = self::assetsDir();
        return is_readable($dir . '/partyard-military-header.jpg')
            && is_readable($dir . '/partyard-military-footer.png');
    }

    /**
     * 2026-05-19: cria um PhpWord pré-carregado com o template MOD_072_V3
     * original do SGQ. O caller adiciona uma nova section com o conteúdo
     * dinâmico (items, compliance matrix, signature) — essa section
     * herda o header/footer corporativo do template e arranca em página
     * nova depois da capa.
     *
     * Devolve null se o template não existe — caller deve fazer fallback
     * para PhpWord::new() + ::apply($section).
     */
    public static function loadOriginalAsPhpWord(): ?\PhpOffice\PhpWord\PhpWord
    {
        if (!self::hasOriginalTemplate()) return null;
        try {
            $reader = \PhpOffice\PhpWord\IOFactory::createReader('Word2007');
            return $reader->load(self::templateDocxPath());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'PartYardMilitaryWordTemplate: falha a carregar .docx original — ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Configuração de Section adequada para receber header/footer images.
     * Margens ajustadas para o banner caber sem cortar conteúdo.
     */
    public static function sectionConfig(bool $forceTemplate = false): array
    {
        $active = $forceTemplate || self::isAvailable();
        return [
            'marginLeft'   => Converter::cmToTwip(1.5),
            'marginRight'  => Converter::cmToTwip(1.5),
            'marginTop'    => $active ? Converter::cmToTwip(3.5) : Converter::cmToTwip(1.8),
            'marginBottom' => $active ? Converter::cmToTwip(2.8) : Converter::cmToTwip(1.8),
            'headerHeight' => $active ? Converter::cmToTwip(3.0) : null,
            'footerHeight' => $active ? Converter::cmToTwip(2.5) : null,
        ];
    }

    /**
     * Injecta o header (banner) e footer (NCAGE/ISO/H&P) MOD_072_V3
     * na secção fornecida. Idempotente.
     *
     * @param Section $section
     * @param array   $opts {
     *     @var string|null $audit_ref    Referência interna SGQ (ex. "RFQ 17509/2026")
     *     @var string|null $document_kind  "Inquiry" | "Work Report" | "Quotation" …
     * }
     * @return bool true se aplicou template, false se assets não disponíveis
     */
    public static function apply(Section $section, array $opts = []): bool
    {
        if (!self::isAvailable()) return false;

        $dir       = self::assetsDir();
        $headerImg = $dir . '/partyard-military-header.jpg';
        $footerImg = $dir . '/partyard-military-footer.png';

        // ── HEADER ─────────────────────────────────────────────────────
        $headerObj = $section->addHeader();
        $headerObj->addImage($headerImg, [
            'width'         => Converter::cmToPoint(18.0),
            'height'        => Converter::cmToPoint(2.6),
            'wrappingStyle' => 'inline',
            'alignment'     => Jc::CENTER,
        ]);

        // ── FOOTER ─────────────────────────────────────────────────────
        $footerObj = $section->addFooter();
        $footerObj->addImage($footerImg, [
            'width'         => Converter::cmToPoint(18.0),
            'height'        => Converter::cmToPoint(2.0),
            'wrappingStyle' => 'inline',
            'alignment'     => Jc::CENTER,
        ]);

        // Audit line — código SGQ + tipo + ref interna + timestamp
        $kind     = (string) ($opts['document_kind'] ?? 'Document');
        $ref      = (string) ($opts['audit_ref']     ?? '');
        $line     = 'MOD_072_V3 · PartYard ' . $kind . ' · SGQ'
                  . ($ref !== '' ? ' · ' . $ref : '')
                  . ' · ' . now()->format('Y-m-d');
        $footerObj->addText(
            self::xmlSafe($line),
            ['size' => 7, 'color' => '94A3B8'],
            ['alignment' => Jc::CENTER]
        );

        return true;
    }

    /**
     * Regista no $phpWord os estilos partilhados do template (Calibri
     * corporate + paleta navy PartYard). Chamado UMA vez por documento.
     */
    public static function registerStyles(PhpWord $phpWord): void
    {
        $phpWord->getCompatibility()->setOoxmlVersion(15);

        // Brand colours (alinhados com TenderInquiryController)
        $defs = [
            'h1'    => ['name' => 'Calibri',  'size' => 18, 'bold' => true, 'color' => '0F1B4C'],
            'h2'    => ['name' => 'Calibri',  'size' => 13, 'bold' => true, 'color' => '0F1B4C'],
            'h3'    => ['name' => 'Calibri',  'size' => 11, 'bold' => true, 'color' => '0F1B4C'],
            'body'  => ['name' => 'Calibri',  'size' => 10],
            'mono'  => ['name' => 'Consolas', 'size' => 9],
            'th'    => ['name' => 'Calibri',  'size' => 9,  'bold' => true, 'color' => 'FFFFFF'],
            'label' => ['name' => 'Calibri',  'size' => 9,  'bold' => true, 'color' => '475569'],
            'muted' => ['name' => 'Calibri',  'size' => 8,  'color' => '6B7280'],
        ];
        foreach ($defs as $key => $style) {
            // Evita warning "Style 'X' already exists" em chamadas múltiplas
            try { $phpWord->addFontStyle($key, $style); } catch (\Throwable $e) {}
        }
    }

    /**
     * Sanitiza string para OOXML — força UTF-8 válido e remove control
     * chars XML-ilegais (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F, 0x7F). Sem
     * isto, bytes do PDF text extraction corrompem o docx ("Word
     * experienced an error trying to open the file").
     */
    public static function xmlSafe(string $s): string
    {
        if ($s === '') return '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s)
                ?: mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
    }
}
