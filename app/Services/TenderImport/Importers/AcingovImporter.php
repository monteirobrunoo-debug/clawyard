<?php

namespace App\Services\TenderImport\Importers;

use App\Models\Tender;
use App\Services\TenderImport\Contracts\TenderImporterInterface;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Acingov / Vortal / PT Concursos importer.
 *
 * Acingov é a plataforma; Vortal opera-a. Os utilizadores PartYard
 * referem-se às duas como "PT Concursos". Aceita ficheiros .xlsx
 * exportados directamente do portal Acingov ou de pesquisas Vortal.
 *
 * AUTO-DETECT de colunas — em vez de exigir nomes exactos como o
 * NspaImporter, o Acingov tem várias colunas com nomes ligeiramente
 * diferentes consoante o tipo de export (procedimentos abertos vs
 * adjudicados vs concursos públicos). Tentamos várias alternativas
 * por campo. Headers tratados case-insensitive e com trim.
 *
 * Layout típico Acingov export:
 *   Procedimento / Referência | Designação / Objecto | Entidade /
 *   Adjudicante | Categoria CPV | Tipo Procedimento | Valor Base /
 *   Preço Base | Prazo Submissão / Data Limite | Data Publicação |
 *   Estado | Concorrentes | Adjudicatário | Valor Adjudicação |
 *   URL / Link
 *
 * TIMEZONE: Acingov mostra horas em Europe/Lisbon. Excel exports
 * costumam ter datas naive — interpretamos como Lisboa wall-clock
 * e convertemos para UTC ao gravar.
 */
class AcingovImporter implements TenderImporterInterface
{
    private const SOURCE_TZ          = 'Europe/Lisbon';
    private const MAX_ROWS           = 50_000;
    private const EMPTY_TAIL_STOP    = 50;

    // Mapeamento canónico → lista de aliases (case-insensitive, trimmed,
    // pontuação trailing removida em mapHeaders). Primeira ocorrência ganha.
    // Acomoda múltiplas variantes encontradas em exports diferentes do
    // Acingov/Vortal + planilhas internas PartYard tipo CONCURSOS_VICENCIO.
    private const COLUMN_ALIASES = [
        'reference' => [
            'procedimento', 'referência', 'referencia', 'nº procedimento',
            'numero procedimento', 'nº referência', 'numero referencia',
            'id procedimento', 'processo', 'rfp_collectivenumber',
            'reference', 'ref',
        ],
        'title' => [
            'designação', 'designacao', 'objecto', 'objeto', 'título',
            'titulo', 'descrição', 'descricao', 'description',
            'rfp_title', 'name', 'concurso', 'tender title',
        ],
        'purchasing_org' => [
            'entidade', 'entidade adjudicante', 'adjudicante', 'comprador',
            'rfp_purchasingorganisation', 'purchasing org', 'organism',
            'organismo', 'cliente',
        ],
        'type' => [
            'tipo procedimento', 'tipo', 'tipo de procedimento',
            'rfp_typedescription', 'procedure type', 'categoria',
            'cpv', 'categoria cpv',
        ],
        'deadline_at' => [
            'prazo submissão', 'prazo submissao', 'data limite',
            'data fim', 'fim apresentação', 'fim apresentacao',
            'rfp_closingdate', 'closing date', 'prazo', 'deadline',
            'submission deadline',
        ],
        'source_modified_at' => [
            'data publicação', 'data publicacao', 'data publicado',
            'rfp_lastmodifieddate', 'last modified', 'modified',
            'data alteração', 'data alteracao',
        ],
        'status' => [
            'estado', 'status', 'situação', 'situacao', 'fase',
            'estado concurso',
        ],
        'offer_value' => [
            'valor base', 'preço base', 'preco base', 'valor estimado',
            'valor da offer sub', 'valor da proposta', 'preço estimado',
            'preco estimado', 'base value', 'estimated value',
        ],
        'currency' => [
            'moeda', 'currency', 'divisa',
        ],
        'result' => [
            'resultado', 'adjudicatário', 'adjudicatario', 'vencedor',
            'winner', 'awarded to',
        ],
        'notes' => [
            'observações', 'observacoes', 'notas', 'notes', 'remarks',
            'coluna1', 'comentário', 'comentario',
            // Planilhas internas PartYard usam "Project"/"Projecto" como
            // tracking interno (números de projeto, "NÃO TRATAR" etc).
            // Vai para notes para não se perder a informação.
            'project', 'projecto',
        ],
        'priority' => [
            'nível', 'nivel', 'prioridade', 'priority', 'urgência', 'urgencia',
        ],
        'url' => [
            'url', 'link', 'endereço', 'endereco', 'web', 'permalink',
        ],
        // Planilhas internas PartYard têm coluna "Colaborador" — mapeia
        // directamente para o auto-link de TenderCollaborator no service.
        'collaborator_name' => [
            'colaborador', 'colaboradora', 'collaborator', 'collab',
            'responsável', 'responsavel', 'assignee',
        ],
    ];

    public function source(): string
    {
        return 'acingov';
    }

    public function parse(string $filePath): iterable
    {
        // Boost memory like NSPA — Acingov exports são geralmente menores
        // (<5k linhas) mas ficheiros com formatação completa podem ter
        // phantom rows também.
        $currentLimit = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        if ($currentLimit >= 0 && $currentLimit < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // 2026-05-19 — pedido directo do operador:
        //   CONCURSOS_VICENCIO.xlsx tem 4 sheets (Folha1/2024/2025/2026)
        //   com ~700 concursos no total. Antes lia só a sheet activa  e
        //   ignorava as outras 3. Agora iteramos todas as sheets.
        $allSheets = $spreadsheet->getAllSheets();
        \Illuminate\Support\Facades\Log::info('AcingovImporter: starting parse', [
            'sheet_count' => count($allSheets),
            'sheet_names' => array_map(fn($s) => $s->getTitle(), $allSheets),
        ]);

        foreach ($allSheets as $sheet) {
            $sheetTitle = $sheet->getTitle();
            yield from $this->parseSheet($sheet, $sheetTitle);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * Parseia UMA worksheet. Extraído de parse() em 2026-05-19 para
     * suportar ficheiros com múltiplas sheets (ex. uma sheet por ano).
     */
    private function parseSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $sheetTitle): iterable
    {
        $headers        = [];
        $headerMap      = []; // canonical_key → column_index
        $emptyRunLength = 0;
        $rowIndex       = 0;
        $headerRow      = 0;     // 0 = ainda não encontrado
        $scannedRows    = [];    // buffer das primeiras 20 rows p/ relatório

        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex++;
            if ($rowIndex > self::MAX_ROWS) break;

            $cells        = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            // 2026-05-19 — pedido directo do operador:
            //   "User eduardo.rio importou CONCURSOS_VICENCIO.xlsx e não
            //    deu em nada, ele não consegue ver"
            // Causa raiz: o parser assumia headers em row 1, mas Acingov
            // exports podem ter 1-3 linhas de preâmbulo (filename, data,
            // totais) antes da linha de headers.
            //
            // Fix: scan as primeiras 20 rows à procura de uma que tenha
            // pelo menos 3 aliases canónicos reconhecidos. Aceita também
            // a row 1 quando bate (comportamento antigo).
            if ($headerRow === 0) {
                if ($rowIndex <= 20) {
                    $scannedRows[$rowIndex] = $cells;
                    $tryHeaders   = array_map(fn($h) => is_string($h) ? trim($h) : $h, $cells);
                    $tryHeaderMap = $this->mapHeaders($tryHeaders);
                    // Heurística: header row precisa de mapear ≥3 canónicos
                    // OU ter pelo menos 'reference' + 'title' (mínimo essential).
                    $hasMin = count($tryHeaderMap) >= 3
                          || (isset($tryHeaderMap['reference']) && isset($tryHeaderMap['title']));
                    if ($hasMin) {
                        $headers   = $tryHeaders;
                        $headerMap = $tryHeaderMap;
                        $headerRow = $rowIndex;
                        \Illuminate\Support\Facades\Log::info('AcingovImporter: header row detected', [
                            'header_row'    => $headerRow,
                            'matched_keys'  => array_keys($headerMap),
                            'header_sample' => array_slice($tryHeaders, 0, 8),
                        ]);
                    }
                    continue;
                }
                // Passámos 20 linhas sem detectar — desistimos e logamos
                // para forensics. O caller verá rows_parsed=0 e mostra UI msg.
                \Illuminate\Support\Facades\Log::warning('AcingovImporter: no header row detected in first 20 rows', [
                    'sampled_rows' => array_slice($scannedRows, 0, 5, true),
                ]);
                break;
            }

            $reference = $this->cellByCanonical($cells, $headerMap, 'reference');
            $title     = $this->cellByCanonical($cells, $headerMap, 'title');

            if (!$reference && !$title) {
                if (++$emptyRunLength >= self::EMPTY_TAIL_STOP) break;
                continue;
            }
            $emptyRunLength = 0;

            // Para Acingov, se faltar reference mas houver title, usa um
            // hash do title como reference para evitar colisões em upserts.
            if (!$reference && $title) {
                $reference = 'acingov-' . substr(md5($title), 0, 12);
            }

            yield [
                'source'                 => 'acingov',
                'reference'              => $reference,
                'title'                  => (string) ($title ?? ''),
                'type'                   => $this->cellByCanonical($cells, $headerMap, 'type'),
                'purchasing_org'         => $this->cellByCanonical($cells, $headerMap, 'purchasing_org'),
                'status'                 => Tender::normaliseStatus(
                    $this->cellByCanonical($cells, $headerMap, 'status')
                ),
                'priority'               => $this->cellByCanonical($cells, $headerMap, 'priority'),
                'deadline_at'            => $this->toUtc(
                    $this->cellByCanonicalRaw($cells, $headerMap, 'deadline_at')
                ),
                'source_modified_at'     => $this->toUtc(
                    $this->cellByCanonicalRaw($cells, $headerMap, 'source_modified_at')
                ),
                // 2026-05-19: extrai candidato a SAP Opp number do Ref.
                // Planilhas internas PartYard (VICENCIO) têm refs SAP-style
                // (5022019630, DMSA 5026006042) no Ref. — antes ficavam só
                // em `reference` e o dashboard mostrava "⚠ sem nº" mesmo
                // quando o concurso JÁ existia em SAP. Pedido directo:
                //   "ficheiro tem as referências em SAP mas não aparece
                //    na tabela; tem de ser igual à da NSPA"
                'sap_opportunity_number' => $this->extractSapOppCandidate($reference),
                'offer_value'            => $this->numeric(
                    $this->cellByCanonicalRaw($cells, $headerMap, 'offer_value')
                ),
                'currency'               => $this->cellByCanonical($cells, $headerMap, 'currency')
                                            ?? 'EUR', // default PT
                'notes'                  => $this->cellByCanonical($cells, $headerMap, 'notes'),
                'result'                 => $this->cellByCanonical($cells, $headerMap, 'result'),
                'collaborator_name'      => $this->cellByCanonical($cells, $headerMap, 'collaborator_name'),
                'raw_metadata'           => array_merge(
                    $this->buildRawMetadata($headers, $cells),
                    ['source_sheet' => $sheetTitle]
                ),
            ];
        }
    }

    /**
     * Build {canonical_key => column_index} from the actual Excel headers,
     * trying each alias case-insensitive against trimmed header text.
     */
    private function mapHeaders(array $headers): array
    {
        // 2026-05-19: normalização agressiva — remove trim, lowercase,
        // strip trailing punctuation ('.', ':') e colapsa whitespace.
        // Necessário para que "Ref." bata com alias "ref", "Estado:"
        // com "estado", "NOTAS  " com "notas", etc.
        $normalise = function ($h): string {
            if (!is_string($h)) return '';
            $s = mb_strtolower(trim($h));
            $s = rtrim($s, ".:; \t\n\r");
            return preg_replace('/\s+/u', ' ', $s) ?: '';
        };

        $normHeaders = [];
        foreach ($headers as $i => $h) {
            $normHeaders[$i] = $normalise($h);
        }

        $map = [];
        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $needle = $normalise($alias);
                $idx    = array_search($needle, $normHeaders, true);
                if ($idx !== false) {
                    $map[$canonical] = $idx;
                    break;
                }
            }
        }
        return $map;
    }

    private function cellByCanonical(array $cells, array $map, string $key): ?string
    {
        if (!isset($map[$key])) return null;
        return $this->trim($cells[$map[$key]] ?? null);
    }

    private function cellByCanonicalRaw(array $cells, array $map, string $key): mixed
    {
        if (!isset($map[$key])) return null;
        return $cells[$map[$key]] ?? null;
    }

    /**
     * 2026-05-19: extrai candidato a SAP Opportunity Number do campo
     * Ref. das planilhas PartYard internas. Heurística calibrada contra
     * o ficheiro CONCURSOS_VICENCIO.xlsx do Eduardo (4 sheets, ~700 refs).
     *
     * Patterns suportados:
     *   1) NSPA-style `NNNNN/YYYY`   → 15461/2025, 17122/2026         → keep as-is
     *   2) Pure numeric 8-12 digits  → 5022019630, 5023000051        → keep as-is
     *   3) Prefix + numeric         → DMSA 5026006042, CLAFA 5025018663 → extrai os dígitos
     *   4) Numeric / metadata       → 4024015675/DA/Q0023/2024       → extrai prefix numeric
     *   5) Numeric com slashes       → 3026004273/684                 → extrai primeiro grupo numeric ≥ 6
     *
     * Devolve null para refs que claramente não são SAP-style
     * (CPI/02/BA5/2026, 821623 com 6 dígitos é ambiguous → null,
     * "-", etc.). Conservador — preferimos "sem nº" a um falso positivo.
     */
    private function extractSapOppCandidate(?string $ref): ?string
    {
        if (!$ref) return null;
        $trimmed = trim($ref);
        if ($trimmed === '' || $trimmed === '-') return null;

        // Pattern 1: NSPA-style "NNNNN/YYYY" (já tem ano)
        if (preg_match('/^(\d{4,6}\/\d{4})$/', $trimmed, $m)) {
            return $m[1];
        }

        // Pattern 2: pure numeric 8-12 digits (SAP B1 doc style)
        if (preg_match('/^(\d{8,12})$/', $trimmed)) {
            return $trimmed;
        }

        // 2026-05-19 refine: separadores expandidos para acomodar
        // variações reais do CONCURSOS_VICENCIO ("DMSA - 5026000749",
        // "GTF16 5025017788", "3026000717-AMN", "3026003935_GM_EGM").
        //
        // Pattern 3: prefix alfanumérico + separador(\s, -, _) + 8-12 digits
        //   DAT 5026000970        DMSA - 5026001346
        //   CLAFA 5025018663      GTF16 5025017788
        if (preg_match('/^[A-Z][A-Z0-9\.\-]*[\s\-_]+(\d{8,12})$/i', $trimmed, $m)) {
            return $m[1];
        }

        // Pattern 4: numeric (8-12) seguido de separador (/_-\s) e metadata
        //   4024015675/DA/Q0023/2024     3026004273/684
        //   3026003935_GM_EGM            3026000717 - AMN
        //   3026000735-AMN
        if (preg_match('/^(\d{8,12})[\/_\-\s].+/', $trimmed, $m)) {
            return $m[1];
        }

        return null;
    }

    private function buildRawMetadata(array $headers, array $cells): array
    {
        $out = [];
        foreach ($cells as $i => $v) {
            $hdr = $headers[$i] ?? "col_{$i}";
            if (is_string($hdr) && $hdr === '') continue;
            $out[(string) $hdr] = $v instanceof \DateTimeInterface
                ? $v->format(\DateTime::ATOM)
                : $v;
        }
        return $out;
    }

    private function trim(mixed $v): ?string
    {
        if ($v === null) return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function numeric(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        $s = str_replace([' ', "\xC2\xA0", '€', '$'], '', (string) $v);
        if (preg_match('/^\-?\d{1,3}(\.\d{3})+,\d+$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    private function toUtc(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') return null;
        try {
            if ($v instanceof \DateTimeInterface) {
                return $this->wallClockToUtc(Carbon::instance($v));
            }
            if (is_numeric($v)) {
                $num = (float) $v;
                if ($num < 1 || $num > 100000) return null;
                $dt = ExcelDate::excelToDateTimeObject($num);
                return $this->wallClockToUtc(Carbon::instance($dt));
            }
            $s = trim((string) $v);
            if ($s === '') return null;
            return Carbon::parse($s, self::SOURCE_TZ)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function wallClockToUtc(Carbon $c): Carbon
    {
        return Carbon::create(
            $c->year, $c->month, $c->day,
            $c->hour, $c->minute, $c->second,
            self::SOURCE_TZ
        )->utc();
    }

    private function memoryLimitBytes(string $s): int
    {
        $s = trim($s);
        if ($s === '' || $s === '-1') return -1;
        $unit = strtolower(substr($s, -1));
        $num  = (int) $s;
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
