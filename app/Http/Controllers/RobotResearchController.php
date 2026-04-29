<?php

namespace App\Http\Controllers;

use App\Models\RobotResearchReport;
use App\Services\AgentCatalog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * /robot/research — timeline of research-council sessions.
 *
 * Each report shows: topic, lead agent, participants, full findings
 * per agent, lead's synthesis, actionable proposals, total LLM cost.
 *
 * Useful for managers + Bruno to see what the agents have been
 * investigating — proves the cooperation isn't theatre, that real
 * web searches + multi-perspective analysis happens.
 */
class RobotResearchController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function index(Request $request)
    {
        $reports = RobotResearchReport::query()
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $catalog = collect(AgentCatalog::all())->keyBy('key');

        return view('robot.research', [
            'reports'      => $reports,
            'agentCatalog' => $catalog,
            'totalCost'    => (float) RobotResearchReport::sum('total_cost_usd'),
            'totalReports' => RobotResearchReport::count(),
        ]);
    }
}
