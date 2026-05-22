<?php

namespace App\Http\Controllers;

use App\Models\AgentMemory;
use Illuminate\Http\Request;

/**
 * /profile/memories — UI para o user ver/editar/apagar as memórias LTM
 * que os agentes têm dele.
 *
 * Pedido implicito: transparência. Sem isto, o user não sabe o que o
 * agente "lembra" — caixa preta. Esta UI permite:
 *   • listar por agente
 *   • editar value/importance
 *   • apagar memórias erradas
 *   • forget all per agent (botão "esqueceu tudo")
 *
 * Scope: user só vê + edita as SUAS memórias. Admins podem ver de
 * outros via /admin/tokens (não desta página).
 */
class MemoriesController extends Controller
{
    /** GET /profile/memories — lista agrupada por agente. */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $memories = AgentMemory::where('user_id', $userId)
            ->orderBy('agent_key')
            ->orderByDesc('importance')
            ->orderByDesc('last_recalled_at')
            ->get();

        // Agrupar por agente para o template
        $grouped = $memories->groupBy('agent_key');

        // Mapa de display names dos agentes (para mostrar "Cor. Rodrigues"
        // em vez de "mildef")
        $agentNames = [
            'mildef'   => '🪖 Cor. Rodrigues (Defesa)',
            'sales'    => '💼 Marco Sales (Vendas)',
            'engineer' => '⚙️ Eng. Victor (R&D)',
            'capitao'  => '⚓ Capitão Vasco (Naval)',
            'hr'       => '👥 Dra. Ana Sobral (RH)',
            'crm'      => '📋 Marta (CRM)',
        ];

        return view('profile.memories', [
            'grouped'    => $grouped,
            'agentNames' => $agentNames,
            'total'      => $memories->count(),
        ]);
    }

    /** PATCH /profile/memories/{memory} — actualiza value/importance. */
    public function update(Request $request, AgentMemory $memory)
    {
        if ($memory->user_id !== auth()->id()) abort(403);

        $data = $request->validate([
            'memory_value' => ['required', 'string', 'max:500'],
            'importance'   => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $memory->update([
            'memory_value' => $data['memory_value'],
            'importance'   => round((float) $data['importance'], 2),
            'source'       => 'explicit',  // promoted: user edited it
        ]);

        return back()->with('status', '✓ Memória actualizada.');
    }

    /** DELETE /profile/memories/{memory} — apaga uma memória. */
    public function destroy(AgentMemory $memory)
    {
        if ($memory->user_id !== auth()->id()) abort(403);
        $memory->delete();
        return back()->with('status', '✓ Memória apagada.');
    }

    /** DELETE /profile/memories/agent/{agentKey} — apaga TODAS deste agente. */
    public function forgetAgent(string $agentKey)
    {
        $count = AgentMemory::where('user_id', auth()->id())
            ->where('agent_key', $agentKey)
            ->delete();
        return back()->with('status', "✓ {$count} memórias apagadas.");
    }
}
