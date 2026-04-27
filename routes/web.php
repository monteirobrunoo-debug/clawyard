<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentPortalController;
use App\Http\Controllers\AgentShareController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AgentActivityController;
use App\Http\Controllers\SapTableController;
use App\Http\Controllers\TenderCollaboratorController;
use App\Http\Controllers\TenderController;
use App\Http\Controllers\TenderImportController;
use Illuminate\Support\Facades\Route;
use App\Services\PatentPdfService;
use App\Models\Discovery;
use Illuminate\Support\Facades\Storage;

// Kyber-1024 key management UI
Route::get('/keys', function () {
    return view('keys.manage');
})->middleware('auth');

// Kyber-1024 decrypt page — public, no login required
Route::get('/decrypt', function () {
    return view('keys.decrypt');
});

// Kyber-1024 decrypt page with server-side token (for large payloads with attachments)
Route::get('/decrypt/{token}', function (string $token) {
    return view('keys.decrypt', ['token' => preg_replace('/[^a-f0-9]/i', '', $token)]);
});

// Outlook Add-in task panes
Route::get('/outlook-addin/read',    function () { return response()->file(public_path('outlook-addin/read.html')); });
Route::get('/outlook-addin/compose', function () { return response()->file(public_path('outlook-addin/compose.html')); });

// Redirect root to dashboard (or login if not authenticated)
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

// ─── Patent PDF routes ────────────────────────────────────────────────────

// Patent library page
Route::get('/patents/library', function () {
    return view('patents.index');
})->middleware(['auth'])->name('patents.library');

// List all downloaded patents + EPO/USPTO discoveries from DB
Route::get('/patents', function () {
    $svc       = new PatentPdfService();
    $downloaded = collect($svc->listDownloaded())->keyBy('patent');

    // Also pull EPO/USPTO discoveries from DB (patent research results)
    $discoveries = Discovery::whereIn('source', ['epo', 'uspto', 'google_patents'])
        ->orderBy('published_date', 'desc')
        ->limit(100)
        ->get();

    // Merge: downloaded PDFs first, then DB-only discoveries without duplicates
    $list = collect($downloaded->values());

    foreach ($discoveries as $d) {
        // Extract patent number from reference_id or title
        $pn = strtoupper(trim($d->reference_id ?? ''));
        // Normalise: remove spaces
        $pn = preg_replace('/\s+/', '', $pn);

        if (!$pn || strlen($pn) < 5) continue;

        // Skip if already in downloaded list
        if ($downloaded->has($pn)) continue;

        // Build external URL
        $extUrl = '#';
        if (str_starts_with($pn, 'EP')) {
            $extUrl = "https://worldwide.espacenet.com/patent/search?q=pn%3D{$pn}";
        } elseif (str_starts_with($pn, 'US')) {
            $extUrl = "https://patents.google.com/patent/{$pn}/en";
        } elseif (str_starts_with($pn, 'WO')) {
            $extUrl = "https://patentscope.wipo.int/search/en/detail.jsf?docId=" . str_replace(['/', '-'], '', $pn);
        } else {
            $extUrl = $d->url ?? '#';
        }

        $list->push([
            'patent'   => $pn,
            'title'    => $d->title ?? null,
            'size_kb'  => null,
            'date'     => $d->published_date ? \Carbon\Carbon::parse($d->published_date)->format('d/m/Y') : null,
            'url'      => null,   // no local PDF
            'ext_url'  => $extUrl,
            'from_db'  => true,
            'summary'  => $d->summary ?? null,
        ]);
    }

    return response()->json($list->values());
})->middleware(['auth']);

// Re-download a specific patent PDF (in case storage was wiped)
Route::post('/patents/redownload/{patent}', function (string $patent) {
    $patent = strtoupper(preg_replace('/[^A-Z0-9\/\-]/', '', $patent));
    // Force re-download by deleting cached copy first
    $path = 'patents/' . $patent . '.pdf';
    if (Storage::disk('local')->exists($path)) {
        Storage::disk('local')->delete($path);
    }
    $svc    = new \App\Services\PatentPdfService();
    $result = $svc->download($patent);
    if ($result) {
        $size = round(Storage::disk('local')->size($result) / 1024);
        return response()->json(['ok' => true, 'patent' => $patent, 'size_kb' => $size]);
    }
    return response()->json(['ok' => false, 'patent' => $patent, 'error' => 'Download failed — source unavailable'], 422);
})->middleware(['auth']);

// Download a specific patent PDF
Route::get('/patents/download/{patent}', function (string $patent) {
    $patent = strtoupper(preg_replace('/[^A-Z0-9\/\-]/', '', $patent));
    $path   = 'patents/' . $patent . '.pdf';
    if (!Storage::disk('local')->exists($path)) {
        abort(404, 'Patent PDF not found');
    }
    return response()->file(
        Storage::disk('local')->path($path),
        ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $patent . '.pdf"']
    );
})->middleware(['auth']);

// SAP diagnostic — admin-gated reachability check (no body leaked).
//
// SECURITY: previously this was a fully public endpoint that performed a
// live SAP /Login with the server's service-account credentials AND
// returned the raw SAP response body — which includes the B1SESSION
// cookie on success. It now:
//   1) requires auth + admin middleware (no anonymous access), and
//   2) returns only status + URL metadata so the session token never
//      crosses the wire a second time even for logged-in admins,
//   3) honours config('services.sap.tls_verify') — no more hardcoded
//      `verify => false`.
Route::middleware(['auth', 'admin'])->get('/sap-diag', function () {
    $url  = config('services.sap.base_url', 'https://sld.partyard.privatcloud.biz/b1s/v1');
    $user = trim(config('services.sap.username', ''), '"\'');
    $pass = trim(config('services.sap.password', ''), '"\'');
    $comp = config('services.sap.company', 'PARTYARD');

    $verify = config('services.sap.tls_verify', true);
    $client = new \GuzzleHttp\Client(['verify' => $verify, 'timeout' => 15, 'http_errors' => false]);
    $res    = $client->post("{$url}/Login", [
        'headers' => ['Content-Type' => 'application/json'],
        'json'    => ['CompanyDB' => $comp, 'UserName' => $user, 'Password' => $pass],
    ]);

    return response()->json([
        'url'       => $url,
        'user'      => $user,
        'status'    => $res->getStatusCode(),
        'reachable' => $res->getStatusCode() !== 0,
        'ok'        => $res->getStatusCode() === 200,
        'when'      => now()->toIso8601String(),
    ]);
});

// Dashboard — agent selector portal
Route::get('/dashboard', function () {
    // Count registered specialist agents dynamically so the hero line
    // never goes stale when we add/remove agents in AgentManager.
    // Excludes the orchestrator (it's a meta-agent, not a specialist).
    $agentCount = count((new \App\Agents\AgentManager())->available()) - 1;

    // Recent conversations for "Continue where you left off" strip.
    // Conversations are scoped by session_id prefix "u{userId}_" — same
    // convention used in ConversationController.
    $userId = auth()->id();
    $user   = auth()->user();
    $recentConversations = \App\Models\Conversation::query()
        ->where('session_id', 'like', 'u' . $userId . '_%')
        ->whereHas('messages')
        ->withCount('messages')
        ->orderBy('updated_at', 'desc')
        ->limit(5)
        ->get();

    // "Partilhados comigo" — things that were specifically attributed to
    // this user (not just the global agent catalog). We surface two
    // kinds on the dashboard so the user lands on everything they
    // personally own in one place:
    //
    //   1) Tender bucket — non-expired concursos assigned to a
    //      TenderCollaborator linked to this user (same forUser() logic
    //      used in /tenders). Count only — full list lives on /tenders.
    //
    //   2) Agent shares — AgentShare rows where this user's email is
    //      either the primary client_email or in the additional_emails
    //      JSON array. Active + non-revoked + non-expired only.
    $myTenderStats = ['total' => 0, 'overdue' => 0, 'next_deadline' => null];
    $mySharedAgents = collect();
    if ($user && $user->email) {
        $expiredCut = now()->copy()->subDays(\App\Models\Tender::OVERDUE_WINDOW_DAYS);
        $tendersQuery = \App\Models\Tender::query()
            ->active()
            ->forUser($userId)
            ->where(function ($q) use ($expiredCut) {
                $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', $expiredCut);
            });
        $total = (clone $tendersQuery)->count();
        if ($total > 0) {
            $myTenderStats['total'] = $total;
            $myTenderStats['overdue'] = (clone $tendersQuery)
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<', now())
                ->count();
            $myTenderStats['next_deadline'] = (clone $tendersQuery)
                ->whereNotNull('deadline_at')
                ->orderBy('deadline_at')
                ->value('deadline_at');
        }

        // MySQL JSON_CONTAINS needs the value as a JSON string. SQLite
        // fallback uses LIKE. We use a portable LIKE on the raw JSON
        // column since additional_emails is stored as a JSON array of
        // strings — it's good enough for the dashboard lookup.
        $emailJsonFragment = '"' . $user->email . '"';
        $mySharedAgents = \App\Models\AgentShare::query()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($expiredCut) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) use ($user, $emailJsonFragment) {
                $q->where('client_email', $user->email)
                  ->orWhere('additional_emails', 'like', '%' . $emailJsonFragment . '%');
            })
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    return view('dashboard', [
        'agentCount'          => $agentCount,
        'recentConversations' => $recentConversations,
        'myTenderStats'       => $myTenderStats,
        'mySharedAgents'      => $mySharedAgents,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

// Chat — with optional agent pre-selected
Route::get('/chat', function () {
    return view('welcome');
})->middleware(['auth'])->name('chat');

// Usage analytics — personal dashboard showing which agents the user
// leans on most, activity patterns and message volume. Pure read-only,
// scoped to the current user's conversations via the u{userId}_ session
// prefix convention (same approach the rest of the app uses).
Route::get('/stats', function () {
    $userId = auth()->id();
    $prefix = 'u' . $userId . '_';

    $conv = \App\Models\Conversation::query()
        ->where('session_id', 'like', $prefix . '%')
        ->whereHas('messages');

    $totalConv = (clone $conv)->count();
    $totalMsg  = (clone $conv)
        ->join('messages', 'conversations.id', '=', 'messages.conversation_id')
        ->count();

    // Top agents by conversation count
    $byAgent = (clone $conv)
        ->selectRaw('agent, COUNT(*) as conv_count')
        ->groupBy('agent')
        ->orderByDesc('conv_count')
        ->limit(10)
        ->get();

    // Daily activity (last 30 days) — counts conversations with at least
    // one message updated in that window, keyed by date.
    $daily = (clone $conv)
        ->where('updated_at', '>=', now()->subDays(30))
        ->selectRaw("DATE(updated_at) as day, COUNT(*) as c")
        ->groupBy('day')
        ->orderBy('day')
        ->get()
        ->keyBy('day');

    // Fill gaps with zero so the chart shows a continuous line.
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = now()->subDays($i)->toDateString();
        $days[] = ['day' => $d, 'count' => (int) ($daily[$d]->c ?? 0)];
    }

    // Hour-of-day histogram (all time, UTC from DB).
    // Portable across Postgres (prod) and SQLite (local dev): Postgres uses
    // EXTRACT(HOUR FROM …), SQLite uses strftime("%H", …). The driver check
    // keeps both environments working without a migration.
    $driver   = \DB::connection()->getDriverName();
    $hourExpr = $driver === 'sqlite'
        ? 'CAST(strftime(\'%H\', messages.created_at) AS INTEGER)'
        : 'CAST(EXTRACT(HOUR FROM messages.created_at) AS INTEGER)';

    $hourly = \App\Models\Message::query()
        ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
        ->where('conversations.session_id', 'like', $prefix . '%')
        ->where('messages.role', 'user')
        ->selectRaw("$hourExpr as hour, COUNT(*) as c")
        ->groupBy('hour')
        ->orderBy('hour')
        ->get()
        ->keyBy('hour');

    $hourBuckets = [];
    for ($h = 0; $h < 24; $h++) {
        $hourBuckets[] = ['hour' => $h, 'count' => (int) ($hourly[$h]->c ?? 0)];
    }

    $avgMsgs = $totalConv > 0 ? round($totalMsg / $totalConv, 1) : 0;

    return view('agents.stats', [
        'totalConv'   => $totalConv,
        'totalMsg'    => $totalMsg,
        'avgMsgs'     => $avgMsgs,
        'byAgent'     => $byAgent,
        'agentByKey'  => \App\Services\AgentCatalog::byKey(),
        'days'        => $days,
        'hourBuckets' => $hourBuckets,
    ]);
})->middleware(['auth', 'verified'])->name('stats');

// Agent Activity — live status cards.
// MUST be registered before the /agents/{key} wildcard below, otherwise
// Laravel matches "activity" as a {key} param and the page 404s.
Route::middleware(['auth'])->get('/agents/activity', [AgentActivityController::class, 'index'])->name('agents.activity');

// hp-history citation proxy — authenticated download of an archived
// document by UUID. The Laravel server does the HMAC signing so the
// browser doesn't need the shared secret. Used by the chat bubble
// renderer to turn `<hp_history>` citation_urls into clickable links.
Route::middleware(['auth'])
    ->get('/hp-history/doc/{docId}', [\App\Http\Controllers\HpHistoryDocController::class, 'show'])
    ->where('docId', '[A-Fa-f0-9\-]{36}')   // UUID shape
    ->name('hp_history.doc');

// Agent profile — per-agent landing page with description, stats, starters
// and recent conversations. Provides a deeper entry point than the dashboard
// card (which just drops you into /chat). Linked from the card's long-press
// or right-click, and from the /agents/{key} URL directly.
Route::get('/agents/{key}', function (string $key) {
    $agent = \App\Services\AgentCatalog::find($key);
    abort_unless($agent, 404, 'Unknown agent');

    $userId = auth()->id();
    $prefix = 'u' . $userId . '_';

    // Count conversations scoped to this user AND this agent.
    // Column is fully qualified because $totalMsgs below joins the
    // messages table (which also has an "agent" column). Without the
    // prefix Postgres raises "ambiguous column reference".
    $baseQuery = \App\Models\Conversation::query()
        ->where('conversations.session_id', 'like', $prefix . '%')
        ->where('conversations.agent', $key);

    $totalConvs = (clone $baseQuery)->whereHas('messages')->count();
    $totalMsgs  = (clone $baseQuery)
        ->join('messages', 'conversations.id', '=', 'messages.conversation_id')
        ->count();
    $weekConvs  = (clone $baseQuery)
        ->whereHas('messages')
        ->where('updated_at', '>=', now()->subDays(7))
        ->count();
    $lastConv   = (clone $baseQuery)
        ->whereHas('messages')
        ->orderBy('updated_at', 'desc')
        ->first();

    $recentConversations = (clone $baseQuery)
        ->whereHas('messages')
        ->withCount('messages')
        ->orderBy('updated_at', 'desc')
        ->limit(10)
        ->get();

    return view('agents.profile', [
        'agent'               => $agent,
        'categories'          => \App\Services\AgentCatalog::categories(),
        'photo'               => \App\Services\AgentCatalog::photo($key),
        'starters'            => \App\Services\AgentCatalog::starters($key),
        'stats'               => [
            'total_conversations' => $totalConvs,
            'total_messages'      => $totalMsgs,
            'week_count'          => $weekConvs,
            'last_used'           => $lastConv ? $lastConv->updated_at->diffForHumans() : null,
        ],
        'recentConversations' => $recentConversations,
    ]);
})->middleware(['auth', 'verified'])->name('agents.profile');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Reports — visible to all authenticated users
Route::middleware(['auth'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/reports/{report}/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::delete('/reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
});

// Daily Executive Briefing
Route::middleware(['auth'])->group(function () {
    Route::get('/briefing', [BriefingController::class, 'index'])->name('briefing');
    Route::get('/briefing/stream', [BriefingController::class, 'stream'])->name('briefing.stream');
    Route::get('/briefing/latest/pdf', [BriefingController::class, 'latestPdf'])->name('briefing.latest.pdf');
    Route::get('/briefing/{report}/pdf', [BriefingController::class, 'pdf'])->name('briefing.pdf');
});

// Conversations — history & PDF export
Route::middleware(['auth'])->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::get('/conversations/{conversation}/pdf', [ConversationController::class, 'pdf'])->name('conversations.pdf');
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
});

// Discoveries — patents & papers tracker
Route::middleware(['auth'])->group(function () {
    Route::get('/discoveries', [DiscoveryController::class, 'index'])->name('discoveries');
    Route::delete('/discoveries/{discovery}', [DiscoveryController::class, 'destroy'])->name('discoveries.destroy');
});

// PSI Intel Bus viewer — live shared context from all agents
Route::middleware(['auth'])->get('/intel', function () {
    $entries = \App\Models\SharedContext::active()
        ->orderBy('created_at', 'desc')
        ->get();
    return view('intel.index', compact('entries'));
})->name('intel');

// SAP Documents — interactive table (Richard SAP)
Route::middleware(['auth'])->group(function () {
    Route::get('/sap/documents', [SapTableController::class, 'index'])->name('sap.documents');
});

// ─── Concursos (Tenders) — dashboard, detail, import, bulk assign ─────────
//
// Gate-checked inside controllers/FormRequests (see AppServiceProvider
// registerTenderGates). Regular users see their own assigns; manager+ see
// everything and may import/bulk-assign.
Route::middleware(['auth'])->group(function () {
    // Import pages are manager+ only; the gate guard is in the controller.
    // NB: /tenders/import and /tenders/collaborators MUST be declared BEFORE
    // /tenders/{tender} so those static segments aren't captured as a tender
    // id by the route model binder.
    Route::get('/tenders/import',  [TenderImportController::class, 'create'])->name('tenders.import.create');
    Route::post('/tenders/import', [TenderImportController::class, 'store'])->name('tenders.import.store');

    // Super-user overview — "who has what / which shares are live".
    // Manager+ only (gate checked in the controller action).
    Route::get('/tenders/overview', [TenderController::class, 'overview'])->name('tenders.overview');

    // Manual nudge — super-user triggers an email reminder to one
    // collaborator listing their current active (non-expired) tenders.
    Route::post(
        '/tenders/overview/remind/{collaborator}',
        [TenderController::class, 'sendReminder']
    )->name('tenders.overview.remind');

    // Collaborators roster CRUD — manager+ only (gate checked in controller).
    Route::get   ('/tenders/collaborators',                                  [TenderCollaboratorController::class, 'index'])->name('tenders.collaborators.index');
    Route::post  ('/tenders/collaborators',                                  [TenderCollaboratorController::class, 'store'])->name('tenders.collaborators.store');
    Route::post  ('/tenders/collaborators/create-users-batch',               [TenderCollaboratorController::class, 'createUsersBatch'])->name('tenders.collaborators.create_users_batch');
    Route::get   ('/tenders/collaborators/{collaborator}/edit',              [TenderCollaboratorController::class, 'edit'])->name('tenders.collaborators.edit');
    Route::patch ('/tenders/collaborators/{collaborator}',                   [TenderCollaboratorController::class, 'update'])->name('tenders.collaborators.update');
    Route::delete('/tenders/collaborators/{collaborator}',                   [TenderCollaboratorController::class, 'destroy'])->name('tenders.collaborators.destroy');
    Route::post  ('/tenders/collaborators/{collaborator}/reactivate',        [TenderCollaboratorController::class, 'reactivate'])->name('tenders.collaborators.reactivate');
    Route::post  ('/tenders/collaborators/{from}/merge/{into}',              [TenderCollaboratorController::class, 'merge'])
        ->where(['from' => '[0-9]+', 'into' => '[0-9]+'])
        ->name('tenders.collaborators.merge');
    Route::patch ('/tenders/collaborators/{collaborator}/toggle-source/{source}', [TenderCollaboratorController::class, 'toggleSource'])
        ->where('source', '[a-z_]+')
        ->name('tenders.collaborators.toggle_source');
    Route::patch ('/tenders/collaborators/{collaborator}/toggle-status/{status}', [TenderCollaboratorController::class, 'toggleStatus'])
        ->where('status', '[a-z_]+')
        ->name('tenders.collaborators.toggle_status');
    Route::post  ('/tenders/collaborators/bulk-sources', [TenderCollaboratorController::class, 'bulkSetSources'])
        ->name('tenders.collaborators.bulk_sources');
    Route::delete('/tenders/collaborators/{collaborator}/force',             [TenderCollaboratorController::class, 'forceDestroy'])->name('tenders.collaborators.force_destroy');
    Route::post  ('/tenders/collaborators/{collaborator}/create-user',       [TenderCollaboratorController::class, 'createUser'])->name('tenders.collaborators.create_user');

    // Bulk assign — also manager+ only (enforced in TenderAssignRequest::authorize).
    Route::post('/tenders/assign', [TenderController::class, 'assign'])->name('tenders.assign');

    // Dashboard + per-tender detail + edit + append-only observation.
    Route::get('/tenders',                      [TenderController::class, 'index'])->name('tenders.index');
    // JSON endpoint for the async SAP Opportunity card on the show page.
    // Registered BEFORE the /{tender} wildcard so "sap-preview" isn't swallowed
    // as a slug — same trap that bit /agents/activity earlier.
    Route::get('/tenders/{tender}/sap-preview', [TenderController::class, 'sapPreview'])->name('tenders.sap_preview');
    Route::get('/tenders/{tender}',             [TenderController::class, 'show'])->name('tenders.show');
    Route::patch('/tenders/{tender}',           [TenderController::class, 'update'])->name('tenders.update');
    Route::post('/tenders/{tender}/observe',    [TenderController::class, 'observe'])->name('tenders.observe');
});

// Schedules page — visible to all authenticated users
Route::get('/schedules', function () {
    return view('admin.schedules');
})->middleware(['auth'])->name('schedules');

// ─── Agent Shares — public client chat ───────────────────────────────────────
// GET page + password/OTP POST: web routes (need session for CSRF).
// POST stream: handled in api.php (no CSRF).
Route::get('/a/{token}', [AgentShareController::class, 'show']);
Route::post('/a/{token}/password', [AgentShareController::class, 'verifyPassword'])
    ->withoutMiddleware('App\Http\Middleware\VerifyCsrfToken');

// OTP flow — throttle hard to slow enumeration / brute force.
Route::post('/a/{token}/otp/request', [AgentShareController::class, 'requestOtp'])
    ->middleware('throttle:10,10'); // 10 req per 10 min per IP
Route::post('/a/{token}/otp/verify', [AgentShareController::class, 'verifyOtp'])
    ->middleware('throttle:15,10'); // 15 attempts per 10 min per IP

// ─── Client portal — one landing for multiple shared agents ──────────────
Route::get('/p/{portalToken}', [AgentPortalController::class, 'show'])
    ->where('portalToken', '[A-Za-z0-9]+');
Route::post('/p/{portalToken}/otp/request', [AgentPortalController::class, 'requestOtp'])
    ->middleware('throttle:10,10')
    ->where('portalToken', '[A-Za-z0-9]+');
Route::post('/p/{portalToken}/otp/verify', [AgentPortalController::class, 'verifyOtp'])
    ->middleware('throttle:15,10')
    ->where('portalToken', '[A-Za-z0-9]+');

// Authenticated: manage shares
Route::middleware(['auth'])->group(function () {
    Route::get('/shares', [AgentShareController::class, 'index'])->name('shares.index');
});

// Admin: create/delete shares (any authenticated user can manage their own)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::post('/shares',                  [AgentShareController::class, 'store'])->name('shares.store');
    // Sends a SINGLE bundled portal email per recipient after a batch of
    // shares is created with the same portal_token and skip_email=true.
    Route::post('/shares/portal-email',     [AgentShareController::class, 'sendPortalEmail'])->name('shares.portalEmail');
    Route::patch('/shares/{share}/toggle',     [AgentShareController::class, 'toggle'])->name('shares.toggle');
    Route::patch('/shares/{share}/toggle-sap', [AgentShareController::class, 'toggleSap'])->name('shares.toggleSap');
    Route::post('/shares/{share}/revoke',      [AgentShareController::class, 'revoke'])->name('shares.revoke');
    Route::get('/shares/{share}/log',       [AgentShareController::class, 'accessLog'])->name('shares.log');
    Route::delete('/shares/{share}',        [AgentShareController::class, 'destroy'])->name('shares.destroy');
});

// Admin Portal
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
    Route::post('/users', [AdminController::class, 'createUser'])->name('admin.users.create');
    Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
    Route::patch('/users/{user}/toggle', [AdminController::class, 'toggleUser'])->name('admin.users.toggle');
    Route::patch('/users/{user}/toggle-promote', [AdminController::class, 'togglePromote'])->name('admin.users.togglePromote');
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('admin.users.delete');
    Route::get('/conversations', [AdminController::class, 'conversations'])->name('admin.conversations');
    Route::get('/conversations/{conversation}', [AdminController::class, 'conversation'])->name('admin.conversation');
    Route::get('/schedules', function () { return view('admin.schedules'); })->name('admin.schedules');

    // Per-user agent access control. Matrix view + cell toggle + preset
    // bulk-apply. The toggle endpoint is JSON so the matrix can update
    // a cell in-place without a full reload.
    Route::get   ('/agent-access',                    [AdminController::class, 'agentAccess'])
        ->name('admin.agentAccess');
    Route::patch ('/users/{user}/agents/{agentKey}',  [AdminController::class, 'toggleAgentAccess'])
        ->where('agentKey', '[a-z_]+')
        ->name('admin.users.toggleAgent');
    Route::post  ('/users/{user}/agents/preset/{preset}', [AdminController::class, 'applyAgentPreset'])
        ->where('preset', '[a-z_]+')
        ->name('admin.users.agentPreset');
});

// QNAP File download — serve files from /var/www/qnapbackup (auth required)
Route::middleware(['auth'])->get('/qnap/file', function (\Illuminate\Http\Request $request) {
    $encoded = $request->query('p', '');
    if (!$encoded) abort(400, 'Missing path');

    $path = base64_decode(strtr($encoded, '-_', '+/'));

    // Security: must be inside /var/www/qnapbackup and no path traversal
    $realBase = realpath('/var/www/qnapbackup');
    $realPath = realpath($path);
    if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase)) {
        abort(403, 'Access denied');
    }
    if (!is_file($realPath)) abort(404, 'File not found');

    $mime = match(strtolower(pathinfo($realPath, PATHINFO_EXTENSION))) {
        'pdf'  => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
        'msg'  => 'application/vnd.ms-outlook',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        default => 'application/octet-stream',
    };

    $filename = basename($realPath);
    return response()->file($realPath, [
        'Content-Type'        => $mime,
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('qnap.file');

// QNAP Index — trigger from browser (admin only)
Route::middleware(['auth'])->get('/admin/qnap-index', function () {
    $svc   = new \App\Services\QnapIndexService();
    $stats = $svc->indexAll();
    return response()->json(['ok' => true, 'stats' => $stats]);
})->name('qnap.index');

// OPcache reset — called by Forge deploy script with secret token
Route::get('/opcache-reset', function () {
    $token = config('services.deploy_token', '');
    if (!$token || request('token') !== $token) {
        abort(403);
    }
    $result = function_exists('opcache_reset') ? opcache_reset() : false;
    return response()->json(['reset' => $result, 'time' => now()->toIso8601String()]);
});

require __DIR__.'/auth.php';
