<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Discovery;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class AgentActivityController extends Controller
{
    // ── Agent metadata ────────────────────────────────────────────────────────
    protected static array $agents = [
        'briefing'  => ['name' => 'Renato',          'role' => 'Estratega Executivo',       'emoji' => '📊', 'color' => '#00aaff',  'actions' => ['A recolher mercados...','A verificar SAP...','A preparar briefing...']],
        'acingov'   => ['name' => 'Dra. Ana',         'role' => 'Contratos & Concursos',     'emoji' => '📋', 'color' => '#ff6600',  'actions' => ['A verificar SAM.gov...','A analisar base.gov.pt...','A filtrar concursos...']],
        'quantum'   => ['name' => 'Prof. Quantum',    'role' => 'I&D Científico',            'emoji' => '⚛️', 'color' => '#9933ff',  'actions' => ['A pesquisar arXiv...','A verificar patentes EPO...','A analisar papers...']],
        'sap'       => ['name' => 'Richard SAP',      'role' => 'SAP B1 & ERP',             'emoji' => '📦', 'color' => '#06b6d4',  'actions' => ['A ligar ao SAP B1...','A verificar faturas...','A analisar encomendas...']],
        'email'     => ['name' => 'Daniel',           'role' => 'Email Comercial',           'emoji' => '✉️', 'color' => '#00cc66',  'actions' => ['A redigir proposta...','A verificar templates...','A personalizar email...']],
        'finance'   => ['name' => 'Dr. Luís',         'role' => 'Análise Financeira',        'emoji' => '💰', 'color' => '#ffaa00',  'actions' => ['A analisar documentos...','A verificar normativos...','A preparar relatório...']],
        'patent'    => ['name' => 'Dra. Sofia IP',    'role' => 'Propriedade Intelectual',   'emoji' => '🔬', 'color' => '#ec4899',  'actions' => ['A pesquisar prior art...','A verificar EPO/USPTO...','A avaliar patenteabilidade...']],
        'energy'    => ['name' => 'Eng. Sofia Energia','role' => 'Energia & Combustível',    'emoji' => '⚡', 'color' => '#f59e0b',  'actions' => ['A calcular Fuzzy TOPSIS...','A avaliar alternativas...','A preparar recomendação...']],
        'engineer'  => ['name' => 'Eng. Victor',      'role' => 'I&D & Engenharia',         'emoji' => '🔧', 'color' => '#76b900',  'actions' => ['A analisar projecto...','A verificar requisitos...','A planear desenvolvimento...']],
        'cyber'     => ['name' => 'ARIA',             'role' => 'Cibersegurança',            'emoji' => '🔐', 'color' => '#ff4444',  'actions' => ['A monitorizar ameaças...','A analisar vulnerabilidades...','A preparar relatório...']],
        'sales'     => ['name' => 'Sales Agent',      'role' => 'Vendas & CRM',             'emoji' => '💼', 'color' => '#ff8800',  'actions' => ['A analisar pipeline...','A verificar clientes...','A preparar proposta...']],
        'support'   => ['name' => 'Support',          'role' => 'Apoio ao Cliente',          'emoji' => '🎧', 'color' => '#4499ff',  'actions' => ['A verificar tickets...','A analisar problema...','A preparar resposta...']],
    ];

    // ── Page view ─────────────────────────────────────────────────────────────
    public function index(): \Illuminate\View\View
    {
        return view('agents.activity');
    }

    // ── API — activity data for all agents ────────────────────────────────────
    public function data(): JsonResponse
    {
        $now     = now();
        $result  = [];

        foreach (self::$agents as $key => $meta) {

            // Last report from this agent
            $report = Report::where('type', $key)
                ->orWhere('type', $key . '_digest')
                ->orderBy('created_at', 'desc')
                ->first();

            // Last 3 discoveries linked to this agent (by source/category heuristic)
            $discoveries = Discovery::orderBy('created_at', 'desc')
                ->when($key === 'quantum', fn($q) => $q->whereIn('source', ['arxiv','peerj','epo']))
                ->when($key === 'patent',  fn($q) => $q->whereIn('source', ['epo','uspto']))
                ->when(!in_array($key, ['quantum','patent']), fn($q) => $q->where('id', '<', 0)) // none
                ->limit(3)
                ->get();

            // Last conversation message from this agent
            $lastMsg = \App\Models\Message::where('agent', $key)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();

            // Active = last activity within 10 minutes
            $lastActive = null;
            $isActive   = false;
            if ($report)  { $lastActive = $report->created_at; }
            if ($lastMsg && (!$lastActive || $lastMsg->created_at > $lastActive)) {
                $lastActive = $lastMsg->created_at;
            }
            if ($lastActive && $lastActive->diffInMinutes($now) < 10) {
                $isActive = true;
            }

            // Build activity items
            $items = [];

            if ($report) {
                $items[] = [
                    'icon'  => '📄',
                    'color' => '#76b900',
                    'title' => $report->title ?: 'Relatório gerado',
                    'sub'   => $report->created_at->diffForHumans(),
                    'type'  => 'report',
                ];
            }

            foreach ($discoveries as $d) {
                $items[] = [
                    'icon'  => '🔍',
                    'color' => '#9933ff',
                    'title' => \Str::limit($d->title, 60),
                    'sub'   => $d->created_at->diffForHumans(),
                    'type'  => 'discovery',
                ];
            }

            if ($lastMsg && count($items) < 3) {
                $preview = \Str::limit(strip_tags($lastMsg->content), 80);
                $items[] = [
                    'icon'  => '💬',
                    'color' => $meta['color'],
                    'title' => $preview ?: 'Resposta enviada',
                    'sub'   => $lastMsg->created_at->diffForHumans(),
                    'type'  => 'message',
                ];
            }

            // Pad with placeholder if no activity
            if (empty($items)) {
                $items[] = [
                    'icon'  => '⏳',
                    'color' => '#444',
                    'title' => 'Aguardando primeira activação',
                    'sub'   => 'Nunca activo',
                    'type'  => 'idle',
                ];
            }

            $result[] = [
                'key'         => $key,
                'name'        => $meta['name'],
                'role'        => $meta['role'],
                'emoji'       => $meta['emoji'],
                'color'       => $meta['color'],
                'is_active'   => $isActive,
                'last_active' => $lastActive?->diffForHumans() ?? 'Nunca',
                'actions'     => $meta['actions'],
                'items'       => array_slice($items, 0, 3),
                'total_reports'     => Report::where('type', $key)->count(),
                'total_discoveries' => in_array($key, ['quantum','patent'])
                    ? Discovery::whereIn('source', ['arxiv','peerj','epo','uspto'])->count()
                    : 0,
            ];
        }

        return response()->json(['ok' => true, 'agents' => $result, 'updated_at' => $now->format('H:i:s')]);
    }
}
