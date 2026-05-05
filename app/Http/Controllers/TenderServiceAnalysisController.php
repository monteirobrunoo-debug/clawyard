<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderServiceAnalysis;
use App\Services\TenderServiceAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenderServiceAnalysisController extends Controller
{
    /**
     * POST /tenders/{tender}/service-analysis — kicks off (or refreshes)
     * the multi-agent service analysis. Sync execution, takes ~30-60s
     * for 5 agents, returns the persisted analysis as JSON.
     */
    public function generate(Request $request, Tender $tender, TenderServiceAnalysisService $svc): JsonResponse
    {
        try {
            $this->authorizeTender($tender);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Convert abort()-thrown HTML 401/403 into JSON so the
            // frontend can show the message instead of "Unexpected token <"
            return response()->json([
                'error'  => $e->getStatusCode() === 401 ? 'unauthorized' : 'forbidden',
                'detail' => $e->getMessage() ?: 'Acesso negado.',
            ], $e->getStatusCode() ?: 403);
        }

        if ($tender->is_confidential) {
            return response()->json([
                'error'  => 'tender_confidential',
                'detail' => 'Concurso confidencial — análise multi-agente desligada (envia conteúdo a 5 agentes Claude). Desmarca a flag se realmente queres correr.',
            ], 403);
        }

        // Reuse cached if fresh and the user didn't force refresh
        $force = $request->boolean('force', false);
        $existing = TenderServiceAnalysis::where('tender_id', $tender->id)->first();
        if ($existing && $existing->status === 'done' && $existing->isFresh(24) && !$force) {
            return response()->json([
                'cached'  => true,
                'view_url' => route('tenders.service-analysis.show', $tender),
                'analysis' => $existing,
            ]);
        }

        try {
            $analysis = $svc->analyse($tender, Auth::id());
        } catch (\Throwable $e) {
            return response()->json([
                'error'  => 'analysis_failed',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'cached'   => false,
            'view_url' => route('tenders.service-analysis.show', $tender),
            'analysis' => $analysis,
        ]);
    }

    /** GET /tenders/{tender}/service-analysis — full view (printable). */
    public function show(Tender $tender): View
    {
        $this->authorizeTender($tender);
        $analysis = TenderServiceAnalysis::where('tender_id', $tender->id)
            ->where('status', 'done')
            ->firstOrFail();

        return view('tenders.service-analysis', [
            'tender'   => $tender,
            'analysis' => $analysis,
        ]);
    }

    /**
     * Authorisation for the multi-agent analysis is intentionally
     * permissive: any authenticated non-guest user can run/view the
     * report. The analysis is internal research — it consults LLMs
     * and stores a report row, but doesn't modify the tender or
     * notify anyone. Forcing collaborator-only would block managers
     * and analysts from running analyses on tenders they're
     * researching but haven't formally claimed.
     *
     * Confidential tenders are still blocked at the controller level
     * (see generate()); they never reach LLMs no matter who clicks.
     */
    private function authorizeTender(Tender $tender): void
    {
        $user = Auth::user();
        if (!$user) abort(401);
        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            abort(403, 'Guests não podem correr análises multi-agente.');
        }
    }
}
