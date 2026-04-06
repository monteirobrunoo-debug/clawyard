<?php

namespace App\Http\Controllers;

use App\Services\SapService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SapTableController extends Controller
{
    protected SapService $sap;

    public function __construct(SapService $sap)
    {
        $this->sap = $sap;
    }

    // ── GET /sap/documents — full page view ──────────────────────────────────
    public function index(): \Illuminate\View\View
    {
        return view('sap.documents');
    }

    // ── GET /api/sap/table — JSON data for the table ─────────────────────────
    public function tableData(Request $request): JsonResponse
    {
        $docType  = $request->query('type',  'invoices');
        $top      = min((int) $request->query('top', 30), 100);
        $dateFrom = $request->query('from');
        $dateTo   = $request->query('to');
        $search   = $request->query('search');

        // Validate doc type
        $allowed = array_keys(SapService::getDocTypeLabels());
        if (!in_array($docType, $allowed, true)) {
            $docType = 'invoices';
        }

        try {
            $rows = $this->sap->getDocumentsForTable($docType, $top, $dateFrom, $dateTo, $search ?: null);

            return response()->json([
                'ok'      => true,
                'type'    => $docType,
                'count'   => count($rows),
                'rows'    => $rows,
                'labels'  => SapService::getDocTypeLabels(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('SapTableController: ' . $e->getMessage());

            return response()->json([
                'ok'    => false,
                'error' => 'Erro ao ligar ao SAP B1: ' . $e->getMessage(),
                'rows'  => [],
            ], 500);
        }
    }

    // ── GET /api/sap/years — year range for the slider ──────────────────────
    public function yearRange(Request $request): JsonResponse
    {
        $docType = $request->query('type', 'invoices');

        try {
            $range = $this->sap->getDocumentYearRange($docType);
            return response()->json(['ok' => true] + $range);
        } catch (\Throwable $e) {
            $year = (int) date('Y');
            return response()->json(['ok' => false, 'min' => $year - 5, 'max' => $year]);
        }
    }
}
