<?php

namespace App\Http\Controllers;

use App\Models\PartOrder;
use App\Services\Robotparts\PartSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only controller for the robot-parts marketplace. The agents do
 * the writing via crons; users only view + download.
 *
 * 2026-05-17: added retry() POST endpoint so cancelled orders can be
 * relaunched from the UI without waiting for the next weekly shop cycle.
 */
class PartOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    /**
     * POST /parts/{order}/retry — re-corre a pesquisa para uma ordem
     * cancelada. Apenas managers, e apenas em ordens em STATUS_CANCELLED.
     *
     * Devolve redirect para a página de origem com flash de sucesso/erro.
     */
    public function retry(PartOrder $order, PartSearchService $svc): RedirectResponse
    {
        if (!auth()->user()?->isManager()) abort(403);

        if ($order->status !== PartOrder::STATUS_CANCELLED) {
            return back()->with('error', "Ordem #{$order->id} não está cancelada — está {$order->status}.");
        }

        $refreshed = $svc->retryCancelled($order);
        $msg = match ($refreshed->status) {
            PartOrder::STATUS_PURCHASED, PartOrder::STATUS_STL_READY
                => "✅ Ordem #{$order->id} re-pesquisada e comprada por \${$refreshed->cost_usd}.",
            PartOrder::STATUS_CANCELLED
                => "⚠️ Ordem #{$order->id} re-pesquisada mas voltou a cancelar: {$refreshed->notes}",
            default
                => "Ordem #{$order->id} agora em estado {$refreshed->status}.",
        };
        return back()->with('status', $msg);
    }

    /**
     * GET /parts/{order}/stl — stream the STL file for download.
     *
     * 404 if the order has no stl_path (CAD generation never reached
     * stl_ready) so users don't get an empty file.
     */
    public function downloadStl(PartOrder $order): BinaryFileResponse|Response
    {
        if (!$order->stl_path || !Storage::disk('local')->exists($order->stl_path)) {
            abort(404, 'STL file not generated for this part yet');
        }

        // Filename users see: "{agent_key}-{order_id}-{slug-of-name}.stl"
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($order->name));
        $slug = trim($slug, '-') ?: 'part';
        $download = sprintf('%s-%d-%s.stl', $order->agent_key, $order->id, $slug);

        return response()->download(
            Storage::disk('local')->path($order->stl_path),
            $download,
            ['Content-Type' => 'model/stl'],
        );
    }
}
