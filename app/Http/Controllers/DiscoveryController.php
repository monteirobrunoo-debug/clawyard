<?php

namespace App\Http\Controllers;

use App\Models\Discovery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DiscoveryController extends Controller
{
    public function index(Request $request)
    {
        $query = Discovery::orderBy('created_at', 'desc');

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('q')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'ilike', '%'.$request->q.'%')
                  ->orWhere('summary', 'ilike', '%'.$request->q.'%');
            });
        }

        $discoveries = $query->paginate(30)->withQueryString();
        $categories  = Discovery::$activityCategories;
        $priorities  = Discovery::$priorityLabels;
        $sources     = Discovery::$sourceLabels;

        // Stats for header
        $stats = [
            'total'    => Discovery::count(),
            'act_now'  => Discovery::where('priority', 'act_now')->count(),
            'monitor'  => Discovery::where('priority', 'monitor')->count(),
            'today'    => Discovery::whereDate('created_at', today())->count(),
        ];

        return view('discoveries.index', compact('discoveries', 'categories', 'priorities', 'sources', 'stats'));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'source'         => 'required|string|in:arxiv,uspto,google_patents',
            'title'          => 'required|string|max:500',
            'summary'        => 'required|string',
            'category'       => 'required|string',
            'activity_types' => 'required|array',
            'priority'       => 'nullable|string|in:act_now,monitor,watch,awareness',
            'relevance_score'=> 'nullable|integer|min:1|max:10',
            'reference_id'   => 'nullable|string|max:100',
            'authors'        => 'nullable|string',
            'opportunity'    => 'nullable|string',
            'recommendation' => 'nullable|string',
            'url'            => 'nullable|string|max:500',
            'published_date' => 'nullable|date',
        ]);

        $discovery = Discovery::create($request->validated());

        return response()->json(['success' => true, 'discovery' => $discovery]);
    }

    public function destroy(Discovery $discovery): JsonResponse
    {
        $discovery->delete();
        return response()->json(['success' => true]);
    }
}
