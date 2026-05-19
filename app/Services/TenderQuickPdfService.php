<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderAttachment;
use App\Models\TenderCollaborator;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Insere PDF — análise automática":
 *   1. Cria stub Tender (source vem do request, ex.: 'marine' ou 'manual')
 *   2. Guarda o PDF como TenderAttachment + extrai texto
 *   3. Pede a Marta CRM para tirar do PDF: cliente, data, serviço, peças,
 *      fornecedores prováveis. Devolve JSON estruturado.
 *   4. Preenche os campos do Tender com o que veio do JSON.
 *   5. Dispara a análise multi-agente (TenderServiceAnalysisService) para
 *      Cor. Rodrigues + Marco Sales + Eng. Victor + ... opinarem.
 *
 * Tudo síncrono — o user vê o spinner do POST e cai directo no
 * /tenders/{id} já preenchido. ~15-30s no caminho normal.
 */
class TenderQuickPdfService
{
    public function __construct(
        private PdfTextExtractor $extractor,
        private TenderServiceAnalysisService $analyser,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * @return array{tender:Tender, extracted:array, analysis_triggered:bool}
     */
    public function handle(UploadedFile $file, string $source = 'manual'): array
    {
        $user = Auth::user();
        if (!$user) {
            throw new \RuntimeException('Auth required for quick PDF analysis.');
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw new \InvalidArgumentException('Quick analysis aceita apenas PDFs.');
        }

        // 1. Stub Tender — usa o filename como título inicial; será reescrito
        //    pela Marta a seguir se ela conseguir extrair um título melhor.
        $originalName = $file->getClientOriginalName();
        $stubTitle    = mb_substr(pathinfo($originalName, PATHINFO_FILENAME), 0, 200);

        $tender = new Tender();
        $tender->source             = $source;
        $tender->reference          = null;
        $tender->title              = $stubTitle ?: 'Análise PDF — ' . now()->format('Y-m-d H:i');
        $tender->type               = 'pdf-auto';
        $tender->status             = Tender::STATUS_PENDING;
        $tender->priority           = 'normal';
        $tender->source_modified_at = now();
        $tender->save();

        // Auto-link ao collaborator do user (replicado de storeManual).
        try {
            $collab = TenderCollaborator::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->first();
            if ($collab) {
                $tender->assigned_collaborator_id = $collab->id;
                $tender->assigned_at              = now();
                $tender->assigned_by_user_id      = $user->id;
                $tender->save();
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        // 2. Persist + extrair texto do PDF.
        $hash      = hash_file('sha256', $file->getRealPath());
        $slug      = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'doc';
        $storedName = $slug . '-' . substr($hash, 0, 8) . '.pdf';
        $relPath   = 'tender-attachments/' . $tender->id . '/' . $storedName;

        Storage::disk('local')->putFileAs(
            'tender-attachments/' . $tender->id,
            $file,
            $storedName,
        );

        $att = TenderAttachment::create([
            'tender_id'           => $tender->id,
            'original_name'       => $originalName,
            'disk_path'           => $relPath,
            'mime_type'           => $file->getClientMimeType(),
            'size_bytes'          => $file->getSize(),
            'file_hash'           => $hash,
            'extraction_status'   => TenderAttachment::STATUS_PENDING,
            'uploaded_by_user_id' => $user->id,
        ]);

        $absolute = Storage::disk('local')->path($relPath);
        $res = $this->extractor->extract($absolute);
        if (($res['ok'] ?? false) === true) {
            $att->extracted_text    = $res['text'];
            $att->extracted_chars   = mb_strlen($res['text']);
            $att->extraction_status = TenderAttachment::STATUS_OK;
        } else {
            $att->extraction_status = TenderAttachment::STATUS_FAILED;
            $att->extraction_error  = mb_substr((string) ($res['error'] ?? 'unknown'), 0, 500);
        }
        $att->save();

        // 3. Extrair campos com Marta CRM se temos texto utilizável.
        $extracted = [];
        if ($att->extraction_status === TenderAttachment::STATUS_OK
            && mb_strlen((string) $att->extracted_text) > 100) {
            try {
                $extracted = $this->extractFieldsFromPdf((string) $att->extracted_text);
            } catch (\Throwable $e) {
                Log::warning('TenderQuickPdf: LLM field extraction failed', [
                    'tender_id' => $tender->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // 4. Aplicar valores extraídos ao tender (se não estiver vazio).
        $this->applyExtractedFields($tender, $extracted);

        // 5. Disparar análise multi-agente. Faz-se síncrono — o user paga
        //    o tempo do POST mas chega ao /tenders/{id} com o painel
        //    Cor. Rodrigues+Marco Sales+resto já pronto.
        $analysisTriggered = false;
        try {
            $this->analyser->analyse($tender, $user->id);
            $analysisTriggered = true;
        } catch (\Throwable $e) {
            Log::warning('TenderQuickPdf: multi-agent analysis failed (non-blocking)', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return [
            'tender'             => $tender->fresh(),
            'extracted'          => $extracted,
            'analysis_triggered' => $analysisTriggered,
        ];
    }

    /**
     * Pede à Marta CRM um JSON com os campos relevantes para preencher
     * o concurso. Tudo num único call para minimizar custo.
     *
     * @return array{cliente?:string, data_limite?:string, servico?:string,
     *               pecas?:list<string>, fornecedores?:list<string>,
     *               nipc?:string, referencia?:string, titulo?:string}
     */
    private function extractFieldsFromPdf(string $pdfText): array
    {
        // Cap a 12k chars para conter latência e custo. O PDF típico de
        // RFP/RFQ tem o essencial nas primeiras páginas.
        $snippet = mb_substr($pdfText, 0, 12000);

        $system = <<<PROMPT
És a Marta CRM do PartYard / HP-Group. Recebes o texto de um PDF de concurso
público / RFQ / RFP e tens de devolver APENAS este JSON (sem markdown):

{
  "titulo": "≤200 chars — descrição do serviço/produto pedido",
  "referencia": "código do procedimento se existir (ex.: 5022019630, 9001/2026), ou null",
  "cliente": "nome da entidade compradora (ex.: NSPA, NCIA, Marinha Portuguesa, Câmara de Lisboa)",
  "nipc": "9 dígitos PT se aparecer, ou null",
  "data_limite": "deadline em formato YYYY-MM-DD se identificável, ou null",
  "servico": "≤300 chars — o que está a ser pedido (manutenção, fornecimento, reparação, …)",
  "pecas": ["item ou peça específico mencionado", ...máx 8...],
  "fornecedores": ["OEM ou fornecedor que faz sentido contactar (MTU, CAT, MAK, SKF, …)", ...máx 5...]
}

REGRAS:
  • Se não tens a certeza absoluta de um valor, devolve null em vez de inventar.
  • NUNCA inventes NIPC, referências, datas ou nomes de clientes.
  • Para pecas/fornecedores devolve só items mencionados ou inferíveis com
    confiança alta (ex.: vê "MTU 396" → fornecedor MTU). Lista vazia é OK.
  • Datas em formato ISO YYYY-MM-DD. Aceita formats PT/EN no input mas
    converte sempre. Se só vir "15 de Janeiro" sem ano, devolve null.
  • Sem fornecedores chineses/russos.
PROMPT;

        $userMsg = "Texto do PDF:\n\n{$snippet}\n\nDevolve o JSON.";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    1500,
        );

        if (!($res['ok'] ?? false)) {
            Log::warning('TenderQuickPdf: dispatcher failed', ['error' => $res['error'] ?? 'unknown']);
            return [];
        }

        $raw = trim((string) ($res['text'] ?? ''));
        if ($raw === '') return [];

        // Tolerant JSON parse — LLM às vezes embrulha em markdown.
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) return [];

        $decoded = json_decode($m[0], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Aplica os valores extraídos ao Tender sem sobrescrever stubs úteis. */
    private function applyExtractedFields(Tender $tender, array $extracted): void
    {
        if (empty($extracted)) return;

        $dirty = false;

        $title = trim((string) ($extracted['titulo'] ?? ''));
        if ($title !== '' && mb_strlen($title) <= 500) {
            $tender->title = $title;
            $dirty = true;
        }

        $ref = trim((string) ($extracted['referencia'] ?? ''));
        if ($ref !== '' && mb_strlen($ref) <= 80) {
            $tender->reference = $ref;
            $dirty = true;
        }

        $client = trim((string) ($extracted['cliente'] ?? ''));
        if ($client !== '' && mb_strlen($client) <= 200) {
            $tender->purchasing_org = $client;
            $dirty = true;
        }

        $deadline = trim((string) ($extracted['data_limite'] ?? ''));
        if ($deadline !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            try {
                $tender->deadline_at = \Carbon\Carbon::parse($deadline);
                $dirty = true;
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Notas concentram o resumo do que a Marta achou — operador vê
        // tudo de uma vez sem precisar de abrir o painel da análise.
        $notesBits = [];
        $servico = trim((string) ($extracted['servico'] ?? ''));
        if ($servico !== '') $notesBits[] = "**Serviço:** {$servico}";

        $pecas = array_filter(array_map('trim', (array) ($extracted['pecas'] ?? [])));
        if (!empty($pecas)) {
            $notesBits[] = "**Peças identificadas:**\n- " . implode("\n- ", $pecas);
        }

        $forn = array_filter(array_map('trim', (array) ($extracted['fornecedores'] ?? [])));
        if (!empty($forn)) {
            $notesBits[] = "**Fornecedores prováveis:**\n- " . implode("\n- ", $forn);
        }

        $nipc = trim((string) ($extracted['nipc'] ?? ''));
        if ($nipc !== '' && preg_match('/^\d{9}$/', $nipc)) {
            $notesBits[] = "**NIPC:** {$nipc}";
        }

        if (!empty($notesBits)) {
            $header = '[Marta · auto-extracted · ' . now()->format('d/m/Y H:i') . "]\n";
            $tender->notes = $header . implode("\n\n", $notesBits);
            $dirty = true;
        }

        if ($dirty) {
            $tender->save();
        }
    }
}
