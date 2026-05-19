<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single procurement opportunity — one row in the unified tenders table.
 *
 * LIFECYCLE
 * ---------
 *   1. Imported from source (NSPA Excel, SAM.gov API, NCIA, …) → status=pending, unassigned
 *   2. Super-user (admin/manager) assigns to a TenderCollaborator
 *   3. Collaborator works it → creates SAP opportunity, updates status
 *   4. Terminates in submetido / ganho / perdido / cancelado / nao_tratar
 *
 * The daily digest (morning + end-of-afternoon) prompts the assigned user
 * to push the record forward whenever `needsActionPrompt()` is true.
 */
class Tender extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source', 'reference', 'title', 'type', 'purchasing_org',
        'status', 'priority', 'is_confidential',
        'assigned_collaborator_id', 'assigned_at', 'assigned_by_user_id',
        'deadline_at', 'source_modified_at',
        'sap_opportunity_number',
        'sap_stage_no', 'sap_opportunity_status', 'sap_stage_updated_at',
        'offer_value', 'currency', 'time_spent_hours',
        'notes', 'result',
        'raw_metadata', 'last_import_id', 'last_digest_sent_at',
        'deadline_alert_sent_at',
        'last_sap_sync_at', 'last_sap_status', 'last_sap_remarks_hash',
        'prelim_analysis', 'prelim_analysed_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_at'            => 'datetime',
            'source_modified_at'     => 'datetime',
            'assigned_at'            => 'datetime',
            'last_digest_sent_at'    => 'datetime',
            'deadline_alert_sent_at' => 'datetime',
            'last_sap_sync_at'       => 'datetime',
            'sap_stage_updated_at'   => 'datetime',
            'sap_stage_no'           => 'integer',
            'offer_value'            => 'decimal:2',
            'time_spent_hours'       => 'decimal:2',
            'raw_metadata'           => 'array',
            'is_confidential'        => 'boolean',
            'prelim_analysis'        => 'array',
            'prelim_analysed_at'     => 'datetime',
        ];
    }

    // ── SAP CRM stage labels (single source of truth) ────────────────────
    //
    // SAP B1 CRM opportunity stages — copy do SapService::getStageLabel()
    // exposto aqui como const para uso em views sem importar SapService.
    // Quando a opportunity_status é 'sos_Won' ou 'sos_Lost', sobrepõe o
    // stage label (Won/Lost são estados terminais).
    public const SAP_STAGE_LABELS = [
        1  => 'Prospecção',
        5  => 'Cotação de Compra',
        6  => 'Cotação de Venda',
        7  => 'Follow Up Vendas',
        8  => 'Possível Venda',
        9  => 'Ordem de Compra',
        10 => 'Ordem de Venda',
    ];

    /**
     * Estado SAP "humano-legível" para mostrar nas listas e no detalhe.
     * Substitui o legacy $tender->status (manual) — single source of truth
     * é agora a opportunity SAP.
     *
     * Prioridade:
     *   1. opportunity_status 'sos_Won'  → "Ganho"
     *   2. opportunity_status 'sos_Lost' → "Perdido"
     *   3. stage_no mapeado para label   → "Cotação de Compra" etc
     *   4. sap_opportunity_number existe mas stage ainda não foi fetched
     *      → "A sincronizar SAP..." (próxima visita ao detalhe puxa)
     *   5. Sem sap_opportunity_number → "Sem oportunidade SAP"
     */
    public function sapStageLabel(): string
    {
        $status = (string) ($this->sap_opportunity_status ?? '');
        if ($status === 'sos_Won')  return 'Ganho';
        if ($status === 'sos_Lost') return 'Perdido';

        $stage = (int) ($this->sap_stage_no ?? 0);
        if ($stage > 0 && isset(self::SAP_STAGE_LABELS[$stage])) {
            return self::SAP_STAGE_LABELS[$stage];
        }

        if (!empty($this->sap_opportunity_number)) {
            return 'A sincronizar SAP…';
        }
        return 'Sem oportunidade SAP';
    }

    /**
     * Classes Tailwind por SAP stage para badge colour-coded no dashboard.
     */
    public function sapStageBadgeClasses(): string
    {
        $status = (string) ($this->sap_opportunity_status ?? '');
        if ($status === 'sos_Won')  return 'bg-emerald-100 text-emerald-800 border-emerald-300';
        if ($status === 'sos_Lost') return 'bg-rose-100 text-rose-800 border-rose-300';

        $stage = (int) ($this->sap_stage_no ?? 0);
        return match (true) {
            $stage === 1      => 'bg-gray-100 text-gray-700 border-gray-300',         // Prospecção
            $stage === 5      => 'bg-amber-100 text-amber-800 border-amber-300',      // Cotação Compra
            $stage === 6      => 'bg-blue-100 text-blue-800 border-blue-300',         // Cotação Venda
            $stage === 7      => 'bg-indigo-100 text-indigo-800 border-indigo-300',   // Follow Up
            $stage === 8      => 'bg-purple-100 text-purple-800 border-purple-300',   // Possível Venda
            $stage === 9      => 'bg-orange-100 text-orange-800 border-orange-300',   // Ordem Compra
            $stage === 10     => 'bg-emerald-100 text-emerald-800 border-emerald-300',// Ordem Venda
            default           => 'bg-gray-50 text-gray-500 border-gray-200',
        };
    }

    // ── Status vocabulary (LEGACY — mantido para back-compat enquanto
    //    migration cache_sap_stage roda; novas views usam sapStageLabel) ──
    public const STATUS_PENDING       = 'pending';        // new import, no status yet
    public const STATUS_EM_TRATAMENTO = 'em_tratamento';
    public const STATUS_SUBMETIDO     = 'submetido';
    public const STATUS_AVALIACAO     = 'avaliacao';
    public const STATUS_CANCELADO     = 'cancelado';
    public const STATUS_NAO_TRATAR    = 'nao_tratar';
    public const STATUS_GANHO         = 'ganho';
    public const STATUS_PERDIDO       = 'perdido';

    /** Statuses where the file is still live and the digest should keep nudging. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_EM_TRATAMENTO,
        self::STATUS_SUBMETIDO,
        self::STATUS_AVALIACAO,
    ];

    /** Statuses that terminate the workflow — no more daily emails. */
    public const TERMINAL_STATUSES = [
        self::STATUS_CANCELADO,
        self::STATUS_NAO_TRATAR,
        self::STATUS_GANHO,
        self::STATUS_PERDIDO,
    ];

    /** Supported source keys — match TenderImport.source. */
    public const SOURCES = [
        'nspa', 'nato', 'sam_gov', 'ncia',
        'acingov', 'vortal', 'anogov', 'ungm', 'unido', 'other',
    ];

    /**
     * 2026-05-19: agrupamento de sources equivalentes para o dropdown
     * de filtros. Pedido directo do operador:
     *   "Junta o Acingov e Vortal e Anogov tudo Acingov/Vortal/Anogov"
     *
     * acingov.pt / vortal.pt / anogov.pt são 3 plataformas PT distintas
     * mas equivalentes (procurement public). O team trata-as como um
     * único bucket "PT Concursos públicos". Quando o user filtra por
     * 'acingov' a query expande para WHERE source IN (acingov,vortal,anogov).
     *
     * Key = canonical source key (o que vai na URL).
     * Value = lista de sources que pertencem ao grupo.
     */
    public const SOURCE_GROUPS = [
        'acingov' => ['acingov', 'vortal', 'anogov'],
    ];

    /**
     * Devolve a lista de sources a usar no filtro WHERE quando o user
     * selecciona um source que é cabeça de grupo. Para sources sem
     * grupo, devolve [source] (singleton).
     */
    public static function expandSourceFilter(string $source): array
    {
        return self::SOURCE_GROUPS[$source] ?? [$source];
    }

    /**
     * Mapping source → canonical Business Partner name no SAP B1.
     * Usado por TenderController::createSapOpp() como fallback quando
     * o purchasing_org do concurso não bate certo com nenhum BP.
     * Adicionado 2026-05-18 por pedido directo: "neste caso é NSPA, não
     * devia preencher a entidade logo pelo concurso?".
     *
     * Os nomes têm de ser os nomes EXACTOS dos BPs no SAP B1 do PartYard.
     * Verifica antes de adicionar novo source: SAP B1 → Negócios →
     * Parceiros → filtra por nome.
     *
     * @var array<string, string>
     */
    public const SOURCE_TO_BP_NAME = [
        'nspa'    => 'NSPA',
        'nato'    => 'NATO',
        'ncia'    => 'NCIA',
        'sam_gov' => 'US Government',   // ajustar se SAP usar outro nome
        'ungm'    => 'UNGM',
        'unido'   => 'UNIDO',
        // acingov / vortal / other / manual deixam-se em branco — fontes
        // PT/genéricas onde o purchasing_org varia muito (cada ministério
        // ou autarquia é um BP diferente). Para essas o operador continua
        // a editar purchasing_org manualmente.
    ];

    /**
     * Mapeamento DIRECTO source → CardCode (Customer ou Lead).
     * Atalho rápido quando sabemos exactamente qual o BP a usar — evita
     * round-trip de search + ranking + filtro Customer/Lead que pode
     * falhar com matches ambíguos ou suppliers a "roubar" o nome.
     *
     * Pedido directo 2026-05-18: "Anspa op codigo de clinete é C000263".
     * Confirmado em produção via searchBusinessPartners() — só os C*
     * são utilizáveis em Sales Opportunities.
     *
     * Para acrescentar novo source, adicionar aqui (precedência absoluta).
     *
     * @var array<string, string>
     */
    public const SOURCE_TO_CARDCODE = [
        'nspa' => 'C000263',   // NSPA - NATO SUPPORT AND PROCUREMENT AGENCY - CIMO (Customer)
        // 'ncia'    => ?       — pendente de confirmação do CardCode certo
        // 'nato'    => ?
        // 'sam_gov' => ?
    ];

    public static function cardCodeForSource(?string $source): ?string
    {
        if (!$source) return null;
        return self::SOURCE_TO_CARDCODE[strtolower(trim($source))] ?? null;
    }

    /**
     * Categorias do campo "Information source" em SAP B1 OOPR.Source.
     * Códigos 100-120 verificados na instância PartYard 2026-05-18.
     * Adicionar novas categorias aqui se SAP B1 ganhar mais Sort codes.
     *
     * Cada entrada é [code => [list_of_keywords]]. O matcher pontua cada
     * categoria pelo número de keywords distintos que aparecem no título
     * do tender (case-insensitive, word boundary). Empate → menor code.
     * 106 "OUTROS/Defense Miscellaneous" é o catch-all default.
     *
     * @var array<int, list<string>>
     */
    public const SAP_SOURCE_CATEGORIES = [
        100 => ['engine', 'motor', 'vehicle', 'viatura', 'truck', 'camião', 'jeep', 'land rover', 'unimog'],
        101 => ['electrical', 'eléctric', 'power', 'generator', 'gerador', 'energia', 'ups', 'transformer'],
        102 => ['pump', 'bomba', 'separator', 'separador'],
        103 => ['hydraulic', 'hidráulic', 'hidraulico', 'cilindro', 'cylinder'],
        104 => ['repair', 'reparação', 'overhaul', 'mro', 'manutenção', 'maintenance'],
        105 => ['shredder', 'destruidor', 'hsm', 'classified', 'classificad'],
        107 => ['pneumatic', 'pneumático', 'pneumatico', 'compressor', 'ar comprimido'],
        108 => ['battery', 'batteries', 'bateria', 'cell', 'lithium'],
        109 => ['combat', 'outfit', 'apresto', 'uniform', 'farda', 'helmet', 'kevlar', 'vest', 'colete'],
        110 => ['avionic', 'avionica', 'avionics', 'amplifier', 'amplificador', 'comunicaç', 'radio'],
        111 => ['galley', 'kitchen', 'cozinha', 'oven', 'forno'],
        112 => ['fire', 'incêndio', 'incendio', 'extinguish', 'fogo', 'sprinkler'],
        113 => ['lubricant', 'lubrificante', 'óleo', 'oil', 'grease', 'massa lubrificante', 'mil-spec'],
        114 => ['radar', 'c4isr', 'sonar', 'sensor'],
        115 => ['container', 'shelter', 'mobile command'],
        116 => ['marine chem', 'decontamin', 'descontamin', 'água', 'desinfect'],
        117 => ['burner', 'queimador', 'heating', 'aquecimento', 'heater'],
        118 => ['mechanical', 'mecânic', 'mecanic', 'weapon', 'arma', 'gun', 'rifle'],
        119 => ['specialty mi', 'medic', 'cirúrgico', 'cirurgico', 'surgical', 'debrider', 'hospital'],
        120 => ['it equip', 'software', 'licens', 'computador', 'laptop', 'server', 'servidor', 'network', 'rede', 'router', 'switch', 'firewall'],
    ];

    /** Catch-all default quando nenhuma categoria bate. */
    public const SAP_SOURCE_DEFAULT = 106;   // OUTROS/Defense Miscellaneous

    /**
     * 2026-05-19 v2: REGRA FINAL DE VISIBILIDADE (refactor).
     * Pedido directo da admin Monica (clarificacao 2026-05-19):
     *   "se for atribuido fica logo importado com o user,
     *    sem nome aparece em todos"
     *
     * Source NAO determina visibilidade. So importa o assigned_collaborator_id:
     *   * assigned_collaborator_id IS NULL  -> pool aberto, todos veem
     *   * assigned_collaborator_id IS NOT NULL  -> so user atribuido + managers
     *
     * PUBLIC_SOURCES mantido para retro-compatibilidade em codigo que
     * ja o referenciava, mas a logica agora ignora-a. As sources
     * Acingov/Vortal/Anogov continuam a beneficiar da regra porque na
     * sua maioria sao importadas sem coluna Colaborador (pool aberto),
     * mas tenders com Colaborador preenchido sao privados.
     */
    public const PUBLIC_SOURCES = ['acingov', 'vortal', 'anogov'];

    /**
     * Infere o InformationSource code (100-120 ou SAP_SOURCE_DEFAULT)
     * a partir do título do tender. Usado por createSapOpp() para
     * pré-popular o campo "Information source" no SAP B1 — pedido
     * directo 2026-05-18: "Information source deveria escolher um tipo
     * de categoria conforme o título".
     *
     * Algoritmo: counts distinct-keyword hits per category, devolve a
     * categoria com mais hits. Empate → menor code (mais "raiz").
     * Zero hits → SAP_SOURCE_DEFAULT (106 OUTROS).
     */
    public function inferSapInformationSource(): int
    {
        // 2026-05-19: pedido directo do operador
        //   "não põe a fonte de informação ou escolhe da tabela conforme
        //    anexado anteriormente"
        // Antes: matching só no título (perdia muitos casos quando o título
        // era genérico tipo "PROVISION OF EQUIPMENT — Lot 17509"). Agora
        // o haystack inclui: title + reference + description + primeiro
        // bloco do extracted_text do primeiro anexo OK (até 4 KB).
        $parts = [
            (string) ($this->title ?? ''),
            (string) ($this->reference ?? ''),
            (string) ($this->description ?? ''),
            (string) ($this->purchasing_org ?? ''),
        ];

        try {
            $firstAtt = $this->attachments()
                ->where('extraction_status', 'ok')
                ->orderBy('id')
                ->first();
            if ($firstAtt && $firstAtt->extracted_text) {
                $parts[] = mb_substr((string) $firstAtt->extracted_text, 0, 4000);
            }
        } catch (\Throwable $e) {
            // attachments() pode não ter rows ou a relação falhar — fallback graceful
        }

        $haystack = mb_strtolower(implode("\n", array_filter($parts)));
        if ($haystack === '') return self::SAP_SOURCE_DEFAULT;

        $bestCode  = self::SAP_SOURCE_DEFAULT;
        $bestScore = 0;

        foreach (self::SAP_SOURCE_CATEGORIES as $code => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                // Word-boundary friendly: garante que "ar comprimido" não
                // bate com "barbacomprimida" mas bate com "ar comprimido"
                // ou "ar-comprimido". Para keywords curtos de 4+ chars
                // usamos preg_match com \b; abaixo de 4 char (raro) fica
                // str_contains.
                $kwLow = mb_strtolower($kw);
                if (mb_strlen($kwLow) >= 4) {
                    if (preg_match('/\b' . preg_quote($kwLow, '/') . '/u', $haystack)) {
                        $score++;
                    }
                } elseif (str_contains($haystack, $kwLow)) {
                    $score++;
                }
            }
            if ($score > $bestScore || ($score === $bestScore && $code < $bestCode && $score > 0)) {
                $bestScore = $score;
                $bestCode  = $code;
            }
        }
        return $bestScore > 0 ? $bestCode : self::SAP_SOURCE_DEFAULT;
    }

    /**
     * Devolve o nome canónico de BP para uma source — ou null se não há
     * mapeamento. NSPA/NATO/NCIA/SAM/UNGM/UNIDO têm; acingov/vortal/other
     * devolvem null e caem no fallback de pedir purchasing_org manual.
     */
    public static function bpNameForSource(?string $source): ?string
    {
        if (!$source) return null;
        return self::SOURCE_TO_BP_NAME[strtolower(trim($source))] ?? null;
    }

    /**
     * Map arbitrary source-language status strings to our canonical enum.
     * Everything unknown collapses to `pending` so the dashboard surfaces
     * "something needs human attention" instead of silently dropping it.
     */
    public static function normaliseStatus(?string $raw): string
    {
        if (!$raw) return self::STATUS_PENDING;
        $key = strtolower(\Illuminate\Support\Str::ascii(trim($raw)));
        return match ($key) {
            'em tratamento', 'em_tratamento', 'in progress'          => self::STATUS_EM_TRATAMENTO,
            'submetido', 'submitted'                                 => self::STATUS_SUBMETIDO,
            'avaliacao', 'avaliação', 'evaluation'                   => self::STATUS_AVALIACAO,
            'cancelado', 'cancelled', 'canceled'                     => self::STATUS_CANCELADO,
            'nao tratar', 'não tratar', 'nao_tratar', 'do not treat' => self::STATUS_NAO_TRATAR,
            'ganho', 'won'                                           => self::STATUS_GANHO,
            'perdido', 'lost'                                        => self::STATUS_PERDIDO,
            default                                                  => self::STATUS_PENDING,
        };
    }

    // ── Relations ────────────────────────────────────────────────────────
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(TenderCollaborator::class, 'assigned_collaborator_id');
    }

    /** PDFs anexados pelo operador. Source-of-truth para Marta CRM,
     *  suggester e Daniel — todos lêem o extracted_text destes rows. */
    public function attachments(): HasMany
    {
        return $this->hasMany(TenderAttachment::class)->orderByDesc('created_at');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(TenderImport::class, 'last_import_id');
    }

    /**
     * Hard cap for "overdue" — anything past this window is considered
     * dead/expired rather than actionable. User rule: 15 days.
     */
    public const OVERDUE_WINDOW_DAYS = 15;

    // ── Scopes ───────────────────────────────────────────────────────────
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Statuses to EXCLUDE from "trabalho ainda a fazer" dashboards.
     *
     * SUBMETIDO = proposta já entregue → deadline original irrelevante,
     * não deve continuar a aparecer em "diárias" nem em "atraso".
     *
     * AVALIACAO continua incluído deliberadamente — o user ainda
     * precisa de fazer follow-up, negociar, ter info pronta para
     * questões do cliente. Não é "trabalho a zero" como o submetido.
     *
     * Estes ainda contam para active() (admin views, listas completas)
     * mas não poluem o "what's on my plate today" do user.
     */
    public const DONE_FROM_USER_POV = [
        self::STATUS_SUBMETIDO,
    ];

    /**
     * "Activos" as the dashboard user reads them: in an active status AND
     * the deadline hasn't passed yet (or there's no deadline at all) AND
     * the user still has work to do (não submetido nem em avaliação).
     * Overdue tenders are intentionally excluded — they live in their own
     * bucket for separate triage.
     */
    public function scopeActiveInProgress(Builder $q): Builder
    {
        return $q->active()
            ->whereNotIn('status', self::DONE_FROM_USER_POV)
            ->where(function ($w) {
                $w->whereNull('deadline_at')->orWhere('deadline_at', '>=', now());
            });
    }

    /**
     * Overdue but still rescuable — past deadline by 0..OVERDUE_WINDOW_DAYS.
     * Older than that is considered expired/abandoned and excluded here.
     * Concursos já submetidos ou em avaliação NÃO aparecem aqui — uma vez
     * entregue a proposta, a deadline original deixa de ser actionable.
     */
    public function scopeOverdue(Builder $q): Builder
    {
        $now    = now();
        $cutoff = $now->copy()->subDays(self::OVERDUE_WINDOW_DAYS);
        return $q->active()
            ->whereNotIn('status', self::DONE_FROM_USER_POV)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', $now)
            ->where('deadline_at', '>=', $cutoff);
    }

    /**
     * Expired — overdue by MORE than OVERDUE_WINDOW_DAYS. These are surfaced
     * in the "Expirado" bucket so someone can bulk-close them.
     */
    public function scopeExpired(Builder $q): Builder
    {
        return $q->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now()->copy()->subDays(self::OVERDUE_WINDOW_DAYS));
    }

    public function scopeForCollaborator(Builder $q, int $collaboratorId): Builder
    {
        return $q->where('assigned_collaborator_id', $collaboratorId);
    }

    /**
     * Per-request cache for the (userId → collaborator rows) lookup
     * used by scopeForUser. The /tenders dashboard calls forUser() 3-4
     * times per render (mine list, all list, stats) — without this each
     * call re-hits the DB. Lives in static state because the array
     * cache driver isn't always wired up across all environments and
     * a simple static array is enough at request scope.
     *
     * Cleared between requests by the framework re-instantiating the
     * model class. Tests can clear explicitly via Tender::flushScopeForUserCache().
     *
     * @var array<int, \Illuminate\Support\Collection>
     */
    private static array $scopeForUserCache = [];

    /** @internal Test helper. */
    public static function flushScopeForUserCache(): void
    {
        self::$scopeForUserCache = [];
    }

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        // Resolve which collaborator rows belong to this user, in strict
        // priority order:
        //
        //   1. Any row with `user_id = $userId` — the authoritative link
        //      set by the saving-hook on TenderCollaborator (email →
        //      User). Explicit ownership; we trust it completely.
        //
        //   2. If (and only if) step 1 returns NOTHING, fall back to rows
        //      whose email matches the user's AND which aren't already
        //      linked to someone else (`user_id IS NULL`). This covers the
        //      legacy case "User created after the collaborator, link
        //      never backfilled". Case-insensitive + trimmed so
        //      "Catarina.Sequeira@…" and "catarina.sequeira@…" don't
        //      silently miss.
        //
        // Why the separation: in the old query we OR-ed the two
        // conditions in a single WHERE. That meant if ANOTHER user's
        // collaborator row happened to carry an email that matched the
        // current user (e.g. a shared Outlook distribution list, or a
        // data-entry typo), the current user would inherit that other
        // user's tenders on top of their own. That's exactly the bug
        // reported 2026-04-24 (catarina.sequeira seeing monica.pereira's
        // dashboard).
        // Per-request cache: the dashboard calls forUser() 3-4 times per
        // render (mine, all, stats). Without this each call re-runs the
        // resolution + (when the strict path is empty) a second query.
        // Cache key is the userId — the resolution doesn't depend on
        // any other state during a single request.
        if (isset(self::$scopeForUserCache[$userId])) {
            $collaborators = self::$scopeForUserCache[$userId];
        } else {
            $email = \App\Models\User::whereKey($userId)->value('email');

            $collaborators = \App\Models\TenderCollaborator::query()
                ->where('user_id', $userId)
                ->get(['id', 'user_id', 'email', 'allowed_sources', 'allowed_statuses']);

            if ($collaborators->isEmpty() && $email) {
                // Only fall back to email matching when there is no explicit
                // user_id link — and even then, only to rows nobody else owns.
                $collaborators = \App\Models\TenderCollaborator::query()
                    ->whereNull('user_id')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
                    ->get(['id', 'user_id', 'email', 'allowed_sources', 'allowed_statuses']);

                // Drift telemetry. Reaching the fallback path means the
                // saving-hook backfill for email→user_id never fired (user
                // was created after the collaborator, or the collab row
                // was raw-inserted). One log line per request lets us spot
                // accumulating "loose" rows that should be linked properly
                // via tenders:link-collaborators. Filtered on level=info
                // so it doesn't pollute warning/error channels.
                if ($collaborators->isNotEmpty()) {
                    \Illuminate\Support\Facades\Log::info('Tender::scopeForUser fell back to email match', [
                        'user_id'         => $userId,
                        'email'           => $email,
                        'matched_collabs' => $collaborators->pluck('id')->all(),
                        'hint'            => 'Run `php artisan tenders:link-collaborators` to backfill user_id.',
                    ]);
                }
            }

            self::$scopeForUserCache[$userId] = $collaborators;
        }

        if ($collaborators->isEmpty()) {
            // 2026-05-19 v2: user sem collab rows ve o pool aberto
            // (tenders sem assignment). Refactor da regra anterior
            // baseada em source.
            return $q->whereNull('assigned_collaborator_id');
        }

        // 2026-05-19 v2: regra final = atribuido a mim OR sem atribuicao.
        // Pedido directo Monica: "se for atribuido fica logo importado
        // com o user, sem nome aparece em todos".
        $collabIds = $collaborators->pluck('id');
        $q->where(function ($w) use ($collabIds) {
            $w->whereIn('assigned_collaborator_id', $collabIds)
              ->orWhereNull('assigned_collaborator_id');
        });

        // Source whitelist (added 2026-04-24). Each collaborator row has an
        // `allowed_sources` JSON array:
        //   NULL  → no restriction (see everything)
        //   []    → blocked from every source
        //   [...] → only these sources
        //
        // Merging across multiple rows for the same user is permissive: if
        // ANY matching row has `allowed_sources = NULL`, the user sees
        // every source. Otherwise we UNION the whitelists.
        $anyUnrestricted = $collaborators->contains(fn($c) => $c->allowed_sources === null);
        if (!$anyUnrestricted) {
            $allowed = $collaborators
                ->flatMap(fn($c) => (array) ($c->allowed_sources ?? []))
                ->unique()
                ->values()
                ->all();

            if (empty($allowed)) {
                // Explicitly blocked from every source.
                $q->whereRaw('1=0');
            } else {
                // 2026-05-19 v2: allowed_sources continua a restringir
                // o que o user pode ver POR SOURCE, mas pool aberto
                // (sem assignment) ainda passa pelo whereIn -- isto
                // permite admins configurarem "user X so faz Acingov"
                // se quiserem. PUBLIC_SOURCES nao se mescla mais aqui
                // porque a regra de visibilidade ja foi resolvida no
                // bloco anterior via assigned_collaborator_id.
                $q->whereIn('source', $allowed);
            }
        }

        // Status whitelist (added 2026-04-25). Same NULL/[]/array
        // semantics as allowed_sources but applied to Tender::status.
        // Use case: a user only handles tenders in `em_tratamento`
        // and shouldn't see the triage queue.
        $anyStatusOpen = $collaborators->contains(fn($c) => $c->allowed_statuses === null);
        if (!$anyStatusOpen) {
            $allowedStatuses = $collaborators
                ->flatMap(fn($c) => (array) ($c->allowed_statuses ?? []))
                ->unique()
                ->values()
                ->all();
            if (empty($allowedStatuses)) {
                $q->whereRaw('1=0');
            } else {
                $q->whereIn('status', $allowedStatuses);
            }
        }

        return $q;
    }

    /**
     * Tenders whose deadline is within the next $daysThreshold days AND
     * still in the future. Past-deadline rows are excluded — they belong
     * to the overdue/expired buckets and would otherwise double-count on
     * the stat cards (a 10-day overdue tender was being counted as
     * "urgent ≤7d" because only the upper bound was enforced).
     */
    public function scopeUrgent(Builder $q, int $daysThreshold = 7): Builder
    {
        $now = now();
        return $q->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '>=', $now)
            ->where('deadline_at', '<=', $now->copy()->addDays($daysThreshold));
    }

    public function scopeNeedingSapOpportunity(Builder $q): Builder
    {
        // Scoped to the live pipeline (active + not expired past the 15d
        // window) so expired tenders don't inflate the "sem nº SAP" badge.
        $cutoff = now()->copy()->subDays(self::OVERDUE_WINDOW_DAYS);
        return $q->active()
            ->whereNotNull('assigned_collaborator_id')
            ->where(fn($w) => $w->whereNull('deadline_at')->orWhere('deadline_at', '>=', $cutoff))
            ->where(fn($w) => $w->whereNull('sap_opportunity_number')->orWhere('sap_opportunity_number', ''));
    }

    /**
     * Find an unlinked tender that matches the opportunity data Marta is
     * about to push to SAP B1. Used to auto-fill `sap_opportunity_number`
     * after Marta creates the opp — saves the operator a copy/paste.
     *
     * Strategy: scan recent unlinked tenders for ANY whose `reference` (or
     * `title`, fallback) appears verbatim in the OpportunityName + Remarks
     * blob. The LONGEST match wins (more specific reference beats a
     * substring collision). Refs shorter than 4 chars are rejected to
     * avoid noise like "RFP" matching every tender.
     *
     * Performance: capped at 200 most-recent unlinked tenders. Run only
     * once per opportunity creation (rare event, ~5/day in steady state).
     *
     * @return self|null  Matched tender, or null if no confident match.
     */
    public static function matchByOpportunityData(string $oppName, string $remarks = ''): ?self
    {
        $haystack = mb_strtolower($oppName . ' ' . $remarks);
        if (trim($haystack) === '') return null;

        $candidates = self::query()
            ->where(function ($w) {
                $w->whereNotNull('reference')->where('reference', '!=', '');
            })
            ->where(function ($w) {
                $w->whereNull('sap_opportunity_number')
                  ->orWhere('sap_opportunity_number', '');
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'reference', 'title']);

        $best = null;
        $bestLen = 0;
        foreach ($candidates as $t) {
            $ref = mb_strtolower((string) $t->reference);
            if (mb_strlen($ref) < 4) continue;          // too short → noise
            if (!str_contains($haystack, $ref)) continue;
            if (mb_strlen($ref) > $bestLen) {
                $best    = $t;
                $bestLen = mb_strlen($ref);
            }
        }
        return $best;
    }

    /**
     * The "live pipeline" — everything still on someone's plate: active
     * status AND deadline either in the future or at most
     * OVERDUE_WINDOW_DAYS in the past. Used for the TOTAL card so the
     * number reflects actionable backlog instead of lifetime imports.
     * Excludes SUBMETIDO/AVALIACAO — proposta entregue não é backlog.
     */
    public function scopeLivePipeline(Builder $q): Builder
    {
        $cutoff = now()->copy()->subDays(self::OVERDUE_WINDOW_DAYS);
        return $q->active()
            ->whereNotIn('status', self::DONE_FROM_USER_POV)
            ->where(function ($w) use ($cutoff) {
                $w->whereNull('deadline_at')->orWhere('deadline_at', '>=', $cutoff);
            });
    }

    // ── Timezone accessors ───────────────────────────────────────────────
    /**
     * User explicitly asked for dual-timezone display (Lisbon + Luxembourg).
     * Storage stays UTC; these accessors format for the UI.
     */
    public function getDeadlineLisbonAttribute(): ?Carbon
    {
        return $this->deadline_at?->copy()->setTimezone('Europe/Lisbon');
    }

    public function getDeadlineLuxembourgAttribute(): ?Carbon
    {
        return $this->deadline_at?->copy()->setTimezone('Europe/Luxembourg');
    }

    /** Negative = overdue. Null if no deadline. */
    public function getDaysToDeadlineAttribute(): ?int
    {
        if (!$this->deadline_at) return null;
        return (int) floor(now()->diffInHours($this->deadline_at, false) / 24);
    }

    public function getUrgencyBucketAttribute(): string
    {
        // User feedback 2026-05-06: concursos já submetidos NÃO devem
        // mostrar badge "em atraso" — proposta entregue, deadline original
        // já não é actionable. Curto-circuita para um bucket dedicado
        // 'submitted' que o template renderiza com tom neutro/azul.
        if (in_array($this->status, self::DONE_FROM_USER_POV, true)) {
            return 'submitted';
        }

        $d = $this->days_to_deadline;
        if ($d === null)                                return 'unknown';
        // Overdue caps at OVERDUE_WINDOW_DAYS — anything older is "expired"
        // (abandoned / needs bulk-close, not actionable).
        if ($d < -self::OVERDUE_WINDOW_DAYS)            return 'expired';
        if ($d < 0)                                     return 'overdue';
        if ($d <= 3)                                    return 'critical';
        if ($d <= 7)                                    return 'urgent';
        if ($d <= 14)                                   return 'soon';
        return 'normal';
    }

    // ── Action prompts for the daily digest ──────────────────────────────
    /** True when the row should appear in today's email. */
    public function needsActionPrompt(): bool
    {
        if (!in_array($this->status, self::ACTIVE_STATUSES, true)) return false;
        // Must be assigned to appear in a collaborator's personal digest
        if (!$this->assigned_collaborator_id)                       return true; // super-user still sees it
        // Still active → always prompt (email includes countdown)
        return true;
    }

    /**
     * Extract the SAP B1 SequentialNo (integer DocEntry) from the free-text
     * `sap_opportunity_number` column so we can call the Service Layer.
     *
     * Users type the identifier in whatever format they see in SAP B1 UI —
     * we've observed at least three conventions in the wild:
     *   "16836/2026"      — DocNum / fiscal year (H&P Group convention)
     *   "SAP-2026-0451"   — legacy prefix style (what the placeholder hints)
     *   "16836"           — bare SequentialNo (power-users who know the API)
     *
     * In every case the FIRST run of ≥4 consecutive digits is the identifier
     * we need (SAP B1 DocEntry starts around 10000 on most installs, so a
     * 4-digit lower bound avoids accidentally picking "2026" out of
     * "SAP-2026-…"). Returns null if the column is empty or unparseable.
     */
    public function getSapSequentialNo(): ?int
    {
        $raw = trim((string) $this->sap_opportunity_number);
        if ($raw === '') return null;

        // Prefer runs of 4+ digits. If the string is just "12" or "999" that's
        // almost certainly wrong, and we'd rather return null than make a
        // bogus Service Layer call.
        if (preg_match('/\d{4,}/', $raw, $m)) {
            return (int) $m[0];
        }
        return null;
    }

    /** @return list<string> Bullet-point prompts shown in the email for this row. */
    public function digestPrompts(): array
    {
        $prompts = [];

        if ($this->status === self::STATUS_PENDING) {
            $prompts[] = 'Ainda sem estado — marcar como Em Tratamento / Não Tratar / etc.';
        }

        if (in_array($this->status, [self::STATUS_EM_TRATAMENTO, self::STATUS_PENDING], true)
            && empty($this->sap_opportunity_number)) {
            $prompts[] = 'Ainda sem nº de Oportunidade SAP — criar no SAP B1 e registar aqui.';
        }

        if ($this->status === self::STATUS_NAO_TRATAR && empty($this->notes)) {
            $prompts[] = 'Indicar razão para "Não Tratar" (notas).';
        }

        $days = $this->days_to_deadline;
        if ($days !== null && $days < 0) {
            $prompts[] = 'Deadline ultrapassado — actualizar resultado (Ganho / Perdido / Cancelado).';
        } elseif ($days !== null && $days <= 3) {
            $prompts[] = "Deadline em {$days} dia(s) — prioridade máxima.";
        }

        return $prompts;
    }

    /**
     * Tender boot hook: when a new row lands (typical: import), queue
     * AnalyseTenderJob so by the time the operator opens the detail
     * page the supplier suggester is pre-warmed.
     *
     * Confidential tenders are short-circuited inside the job itself
     * (defence in depth — even if the flag is set after creation, the
     * job won't run if the tender is_confidential when it executes).
     */
    protected static function booted(): void
    {
        static::created(function (Tender $tender) {
            try {
                \App\Jobs\AnalyseTenderJob::dispatch($tender->id);
            } catch (\Throwable $e) {
                // Queue not available (e.g. migration replay context).
                // Pre-analysis is best-effort — never block creation.
                \Illuminate\Support\Facades\Log::info('AnalyseTenderJob dispatch skipped', [
                    'tender_id' => $tender->id,
                    'reason'    => $e->getMessage(),
                ]);
            }
        });
    }
}
