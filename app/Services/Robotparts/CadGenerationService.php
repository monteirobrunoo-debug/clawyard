<?php

namespace App\Services\Robotparts;

use App\Models\PartOrder;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3: turn the part description into a CNC-ready 3D file.
 *
 * Pipeline:
 *   1. Buyer agent generates OpenSCAD code (LLM call) describing the
 *      part geometrically (cube, cylinder, hull, difference, etc.).
 *      Stored on part_orders.design_scad as the canonical source.
 *   2. We try to invoke `openscad --export-format binstl` to convert
 *      to .stl. If the binary is present, save under storage/app/parts/
 *      and transition to 'stl_ready'.
 *   3. If openscad isn't installed (most droplets), we keep design_scad
 *      and leave status at 'designing' — operator can later install
 *      openscad and re-run, or pipe the .scad to any CAD-aware service.
 *
 * Design choices:
 *   • OpenSCAD over Fusion360-API / SolveSpace because it's text-only
 *     scriptable, has a CLI binary, and the LLM does well at it (small
 *     primitive set, minimal syntax, 30 years of online examples).
 *   • Hard size cap (50mm³) in the prompt so we don't generate parts
 *     bigger than typical hobbyist CNC beds (200×200×100mm easily fits).
 *   • Prompt insists "no comments, no fences" — production validators
 *     reject markdown wrappers.
 */
class CadGenerationService
{
    public function __construct(
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Generate CAD for a single PartOrder. Returns the same order with
     * updated status. Idempotent: if already past designing, no-op.
     *
     *   purchased → designing → stl_ready (if openscad available)
     *                          stays at 'designing' if no binary
     */
    public function generate(PartOrder $order): PartOrder
    {
        // Idempotent: only act on freshly-purchased orders.
        if ($order->status !== PartOrder::STATUS_PURCHASED) {
            return $order;
        }

        $order->status = PartOrder::STATUS_DESIGNING;
        $order->save();

        try {
            $scad = $this->generateScadCode($order);
            if ($scad === null) {
                $order->notes = 'CadGenerationService: LLM dispatch failed for OpenSCAD code.';
                $order->save();
                return $order;
            }

            $order->design_scad = $scad;
            $order->save();

            // Try to convert to STL. If openscad isn't installed we keep
            // the .scad and stay in 'designing' state — partial progress,
            // not a failure.
            $stlRel = $this->convertToStl($order, $scad);
            if ($stlRel !== null) {
                $order->stl_path   = $stlRel;
                $order->designed_at = now();
                $order->status     = PartOrder::STATUS_STL_READY;
                $order->save();
            } else {
                $order->notes = 'CadGenerationService: SCAD generated, STL conversion skipped (openscad binary missing or failed). Run `openscad --export-format binstl` manually on design_scad.';
                $order->save();
            }

            return $order;
        } catch (\Throwable $e) {
            Log::error('CadGenerationService: crashed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            $order->notes = 'CadGenerationService: ' . mb_substr($e->getMessage(), 0, 200);
            $order->save();
            return $order;
        }
    }

    /**
     * Ask the LLM to write OpenSCAD code for the part. Returns the
     * raw .scad text or null on dispatch failure.
     */
    private function generateScadCode(PartOrder $order): ?string
    {
        $system = 'You are an expert OpenSCAD CAD designer. Generate VALID OpenSCAD code '
                . 'for a small robot body part. Hard rules:'  . "\n"
                . '  - Output ONLY raw .scad code. No markdown fences, no comments at start, no preamble.'  . "\n"
                . '  - Size constraint: bounding box must fit in 50×50×50 mm.'  . "\n"
                . '  - Primitives only: cube, sphere, cylinder, hull, union, difference, translate, rotate.'  . "\n"
                . '  - No external libraries (no `use <…>;` or `include <…>;`).'  . "\n"
                . '  - Add `$fn=64;` at the top for smooth curves.'  . "\n"
                . '  - Code MUST compile via `openscad --export-format binstl input.scad -o output.stl` without error.';

        $user = "Part name: {$order->name}\n\n"
              . "Description: " . ($order->description ?: '(no description provided)') . "\n\n"
              . 'Generate the .scad code now. Code only.';

        $res = $this->dispatcher->dispatch($system, $user, maxTokens: 1500);
        if (!($res['ok'] ?? false)) return null;

        $raw = trim((string) $res['text']);
        // Strip markdown fences defensively in case the model added them anyway.
        if (preg_match('/^```(?:\w+)?\s*(.+?)\s*```$/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        return $raw !== '' ? $raw : null;
    }

    /**
     * Invoke `openscad` to convert .scad → .stl. Returns the relative
     * Storage path on success, null on failure (binary missing, syntax
     * error in scad, timeout, etc.).
     */
    private function convertToStl(PartOrder $order, string $scad): ?string
    {
        // Quick check: is the binary available? Cached per request via
        // the Laravel Process facade in real use, but for this MVP we
        // just probe with `which`.
        $whichOutput = trim((string) shell_exec('which openscad 2>/dev/null'));
        if ($whichOutput === '') {
            return null;
        }

        // Write .scad to a temp file, invoke openscad, capture STL.
        $tmpScad = tempnam(sys_get_temp_dir(), 'part_') . '.scad';
        $tmpStl  = tempnam(sys_get_temp_dir(), 'part_') . '.stl';
        file_put_contents($tmpScad, $scad);

        // 30s timeout — small parts compile in <1s normally.
        $cmd = sprintf(
            'timeout 30 openscad --export-format binstl %s -o %s 2>&1',
            escapeshellarg($tmpScad),
            escapeshellarg($tmpStl),
        );
        $output = shell_exec($cmd);
        $stlExists = file_exists($tmpStl) && filesize($tmpStl) > 0;

        if (!$stlExists) {
            Log::warning('CadGenerationService: openscad failed', [
                'order_id' => $order->id,
                'output'   => mb_substr((string) $output, 0, 400),
            ]);
            @unlink($tmpScad);
            @unlink($tmpStl);
            return null;
        }

        // Move the STL into Laravel's storage so the controller can
        // serve it later.
        $relPath = "parts/{$order->id}.stl";
        Storage::disk('local')->put($relPath, file_get_contents($tmpStl));

        @unlink($tmpScad);
        @unlink($tmpStl);

        return $relPath;
    }
}
