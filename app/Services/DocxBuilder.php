<?php

namespace App\Services;

/**
 * Minimal Word .docx generator from markdown — sem dependências
 * externas (usa ZipArchive + DOMDocument built-in).
 *
 * .docx é apenas um zip com XMLs (Open XML / WordprocessingML).
 * Esta classe escreve um docx mínimo válido suportando:
 *   • # H1, ## H2, ### H3
 *   • Parágrafos
 *   • **bold** e *italic*
 *   • Listas com - ou *
 *   • Tabelas markdown (| col | col |)
 *   • Quebras de linha duplas → novo parágrafo
 *
 * Não suporta (mantém-se simples): imagens, footnotes, headers/
 * footers, campos avançados. Para o WorkReportAgent isto chega
 * porque a output dele é texto + tabelas.
 */
class DocxBuilder
{
    public function buildFromMarkdown(string $markdown, string $title = 'Work Report'): string
    {
        $bodyXml = $this->markdownToWordXml($markdown);

        $documentXml = $this->wrapDocumentXml($bodyXml);
        $stylesXml   = $this->getStylesXml();
        $relsXml     = $this->getRelsXml();
        $contentTypes= $this->getContentTypesXml();
        $appXml      = $this->getAppXml();
        $coreXml     = $this->getCoreXml($title);

        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip for docx');
        }

        $zip->addFromString('[Content_Types].xml',          $contentTypes);
        $zip->addFromString('_rels/.rels',                  $relsXml);
        $zip->addFromString('word/document.xml',            $documentXml);
        $zip->addFromString('word/styles.xml',              $stylesXml);
        $zip->addFromString('word/_rels/document.xml.rels', $this->getDocumentRelsXml());
        $zip->addFromString('docProps/app.xml',             $appXml);
        $zip->addFromString('docProps/core.xml',            $coreXml);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    // ─── Markdown → WordprocessingML ────────────────────────────────

    private function markdownToWordXml(string $md): string
    {
        $blocks = preg_split("/\r?\n\r?\n+/", trim($md));
        $out    = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            // Heading
            if (preg_match('/^(#{1,3})\s+(.+)$/m', $block, $m)) {
                $level = strlen($m[1]);
                $heading = $this->cleanInline($m[2]);
                $style   = ['Heading1','Heading2','Heading3'][$level - 1];
                $out[] = "<w:p><w:pPr><w:pStyle w:val=\"{$style}\"/></w:pPr><w:r><w:t xml:space=\"preserve\">{$heading}</w:t></w:r></w:p>";
                continue;
            }

            // Markdown table (| a | b |)
            if (str_contains($block, '|') && preg_match('/^\s*\|/m', $block)) {
                $out[] = $this->renderTable($block);
                continue;
            }

            // List
            $lines = preg_split("/\r?\n/", $block);
            $isList = !empty(array_filter($lines, fn($l) => preg_match('/^\s*[-*]\s+/', $l)));
            if ($isList) {
                foreach ($lines as $line) {
                    if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $mi)) {
                        $item = $this->renderInline(trim($mi[1]));
                        $out[] = "<w:p><w:pPr><w:pStyle w:val=\"ListBullet\"/></w:pPr>{$item}</w:p>";
                    }
                }
                continue;
            }

            // Plain paragraph (each \n inside becomes a soft break)
            $paragraphRuns = '';
            foreach ($lines as $idx => $line) {
                if ($idx > 0) $paragraphRuns .= '<w:r><w:br/></w:r>';
                $paragraphRuns .= $this->renderInline($line);
            }
            $out[] = "<w:p>{$paragraphRuns}</w:p>";
        }

        return implode("\n", $out);
    }

    /** Render inline markdown (**bold**, *italic*) to <w:r> nodes. */
    private function renderInline(string $line): string
    {
        $line = $this->escape($line);
        // Convert **bold** placeholders to a recognizable token for splitting
        $tokens = preg_split('/(\*\*[^*]+\*\*|\*[^*]+\*)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        $runs = '';
        foreach ($tokens as $t) {
            if ($t === '') continue;
            $bold = false; $italic = false; $text = $t;
            if (preg_match('/^\*\*(.+)\*\*$/', $t, $m)) { $bold = true;   $text = $m[1]; }
            elseif (preg_match('/^\*(.+)\*$/', $t, $m)) { $italic = true; $text = $m[1]; }
            $rPr = '';
            if ($bold || $italic) {
                $rPr = '<w:rPr>';
                if ($bold)   $rPr .= '<w:b/>';
                if ($italic) $rPr .= '<w:i/>';
                $rPr .= '</w:rPr>';
            }
            $runs .= "<w:r>{$rPr}<w:t xml:space=\"preserve\">{$text}</w:t></w:r>";
        }
        return $runs;
    }

    private function renderTable(string $block): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $block))));
        $rows  = [];
        foreach ($lines as $line) {
            if (preg_match('/^\|?\s*[-:|\s]+\|?\s*$/', $line)) continue; // separator row
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $rows[] = $cells;
        }
        if (empty($rows)) return '';

        $tblXml  = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/><w:bottom w:val="single" w:sz="4" w:color="auto"/><w:right w:val="single" w:sz="4" w:color="auto"/><w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/></w:tblBorders></w:tblPr>';

        foreach ($rows as $i => $row) {
            $tblXml .= '<w:tr>';
            foreach ($row as $cell) {
                $isHeader = $i === 0;
                $rPr = $isHeader ? '<w:rPr><w:b/></w:rPr>' : '';
                $cellText = $this->escape($this->cleanInline($cell));
                $tblXml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>'
                        . "<w:p><w:r>{$rPr}<w:t xml:space=\"preserve\">{$cellText}</w:t></w:r></w:p></w:tc>";
            }
            $tblXml .= '</w:tr>';
        }
        $tblXml .= '</w:tbl>';
        return $tblXml;
    }

    private function cleanInline(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*/', '$1', $s) ?? $s;
        $s = preg_replace('/\*(.+?)\*/',   '$1', $s) ?? $s;
        $s = preg_replace('/`(.+?)`/',     '$1', $s) ?? $s;
        return $this->escape($s);
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ─── XML scaffolding (boilerplate Open XML) ─────────────────────

    private function wrapDocumentXml(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $bodyXml
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
            . '</w:body></w:document>';
    }

    private function getStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="ListBullet"><w:name w:val="List Bullet"/><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr></w:style>'
            . '</w:styles>';
    }

    private function getRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function getDocumentRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function getContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
    }

    private function getAppXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>ClawYard</Application></Properties>';
    }

    private function getCoreXml(string $title): string
    {
        $title = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $now   = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . "<dc:title>{$title}</dc:title>"
            . '<dc:creator>ClawYard · Eng. Repair</dc:creator>'
            . "<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created>"
            . '</cp:coreProperties>';
    }
}
