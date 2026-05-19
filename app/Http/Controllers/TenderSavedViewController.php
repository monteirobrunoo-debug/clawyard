<?php

namespace App\Http\Controllers;

use App\Models\TenderSavedView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD mínimo para tender saved views. Cada user gere as suas.
 *
 * Routes (em routes/web.php, sob middleware auth):
 *   POST   /tenders/saved-views        store    — guarda os filtros actuais
 *   DELETE /tenders/saved-views/{view} destroy  — apaga uma view
 */
class TenderSavedViewController extends Controller
{
    private const MAX_PER_USER = 12; // ≥ chega para 12 chips na barra

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        if (!$user) abort(403);

        $data = $request->validate([
            'name'             => ['required', 'string', 'min:2', 'max:80'],
            'source'           => ['nullable', 'string', 'max:40'],
            'status'           => ['nullable', 'string', 'max:40'],
            'urgency'          => ['nullable', 'string', 'max:40'],
            'collaborator_id'  => ['nullable', 'integer'],
            'q'                => ['nullable', 'string', 'max:200'],
            'sort'             => ['nullable', 'string', 'max:30'],
            'dir'              => ['nullable', 'string', 'in:asc,desc'],
        ]);

        // Cap por user — força o user a apagar antes de adicionar mais.
        if (TenderSavedView::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            return back()->with('error', 'Limite de ' . self::MAX_PER_USER . ' views guardadas atingido. Apaga uma antes de criar nova.');
        }

        $filters = collect($data)->except('name')->filter(fn ($v) => $v !== null && $v !== '')->all();

        TenderSavedView::create([
            'user_id'    => $user->id,
            'name'       => $data['name'],
            'filters'    => $filters,
            'sort_order' => TenderSavedView::where('user_id', $user->id)->max('sort_order') + 1,
        ]);

        return back()->with('status', "✓ View «{$data['name']}» guardada.");
    }

    public function destroy(TenderSavedView $view): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        if (!$user || $view->user_id !== $user->id) abort(403);
        $name = $view->name;
        $view->delete();
        return back()->with('status', "✓ View «{$name}» apagada.");
    }
}
