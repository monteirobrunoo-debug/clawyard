<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderImportRequest;
use App\Models\TenderImport;
use App\Services\TenderImport\TenderImportService;
use Illuminate\Support\Facades\Auth;

/**
 * Handles the Excel upload that feeds the tenders table.
 *
 * The `tenders.import` gate gates both the form page and the store action.
 * Uploaded files are kept only long enough to parse — we do NOT persist
 * them to disk beyond tender_imports.file_hash (for duplicate detection).
 */
class TenderImportController extends Controller
{
    public function create(TenderImportService $service)
    {
        if (!Auth::user()->can('tenders.import')) abort(403);

        return view('tenders.import', [
            'sources' => $service->availableSources(),
            'recent'  => TenderImport::with('user')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(TenderImportRequest $request, TenderImportService $service)
    {
        $file   = $request->file('file');
        $source = $request->input('source');

        try {
            $audit = $service->import(
                source: $source,
                filePath: $file->getRealPath(),
                originalName: $file->getClientOriginalName(),
                userId: Auth::id(),
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('tenders.import.create')
                ->withErrors(['file' => 'Falha na importação: ' . $e->getMessage()]);
        }

        return redirect()
            ->route('tenders.index')
            ->with('status', $audit->summary);
    }
}
