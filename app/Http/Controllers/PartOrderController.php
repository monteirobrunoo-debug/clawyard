<?php

namespace App\Http\Controllers;

use App\Models\PartOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only controller for the robot-parts marketplace. The agents do
 * the writing via crons; users only view + download.
 */
class PartOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
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
