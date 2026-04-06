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

            // null means SAP login failed — surface a clear message
            if ($rows === null) {
                $diag = $this->sap->testConnection();
                return response()->json([
                    'ok'    => false,
                    'error' => $diag['message'] ?? 'Não foi possível ligar ao SAP B1.',
                    'hint'  => $diag['hint'] ?? '',
                    'rows'  => [],
                ], 200);
            }

            return response()->json([
                'ok'      => true,
                'type'    => $docType,
                'count'   => count($rows),
                'rows'    => $rows,
                'labels'  => SapService::getDocTypeLabels(),
            ], 200);
        } catch (\Throwable $e) {
            \Log::error('SapTableController: ' . $e->getMessage());

            // Always HTTP 200 so JS can read the error body
            return response()->json([
                'ok'    => false,
                'error' => 'Erro SAP B1: ' . $e->getMessage(),
                'rows'  => [],
            ], 200);
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

    // ── GET /api/sap/ping — test SAP connection ──────────────────────────────
    // Always returns HTTP 200 so the browser JS can read the JSON body.
    // The 'ok' field inside the payload indicates success/failure.
    public function ping(): JsonResponse
    {
        $result = $this->sap->testConnection();
        return response()->json($result, 200);
    }
}
