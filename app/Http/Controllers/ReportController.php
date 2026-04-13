<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * GET /reports — List all reports (view)
     */
    public function index()
    {
        $reports = Report::orderBy('created_at', 'desc')->paginate(20);
        return view('reports.index', compact('reports'));
    }

    /**
     * POST /api/reports — Save a report from an agent
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title'   => 'required|string|max:255',
            'type'    => 'required|string|max:50',
            'content' => 'required|string',
            'summary' => 'nullable|string|max:500',
        ]);

        $report = Report::create([
            'title'      => $request->input('title'),
            'type'       => $request->input('type'),
            'content'    => $request->input('content'),
            'summary'    => $request->input('summary', substr($request->input('content'), 0, 300)),
            'user_id'    => auth()->id(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'report' => $report]);
    }

    /**
     * GET /reports/{id} — View single report
     */
    public function show(Report $report)
    {
        return view('reports.show', compact('report'));
    }

    /**
     * GET /reports/{id}/pdf — Download as PDF (simple HTML-to-print)
     */
    public function pdf(Report $report)
    {
        return view('reports.pdf', compact('report'));
    }

    /**
     * DELETE /reports/{id}
     */
    public function destroy(Report $report): JsonResponse
    {
        $report->delete();
        return response()->json(['success' => true]);
    }
}
