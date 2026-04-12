<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentShareController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AgentActivityController;
use App\Http\Controllers\SapTableController;
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

// SAP diagnostic — temporary, remove after fix
Route::get('/sap-diag', function () {
    $url  = config('services.sap.base_url', 'https://sld.partyard.privatcloud.biz/b1s/v1');
    $user = trim(config('services.sap.username', ''), '"\'');
    $pass = trim(config('services.sap.password', ''), '"\'');
    $comp = config('services.sap.company', 'PARTYARD');

    $client = new \GuzzleHttp\Client(['verify' => false, 'timeout' => 15, 'http_errors' => false]);
    $res    = $client->post("{$url}/Login", [
        'headers' => ['Content-Type' => 'application/json'],
        'json'    => ['CompanyDB' => $comp, 'UserName' => $user, 'Password' => $pass],
    ]);

    return response()->json([
        'url'    => $url,
        'user'   => $user,
        'status' => $res->getStatusCode(),
        'body'   => json_decode($res->getBody()->getContents(), true),
    ]);
});

// Dashboard — agent selector portal
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Chat — with optional agent pre-selected
Route::get('/chat', function () {
    return view('welcome');
})->middleware(['auth'])->name('chat');

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

// Agent Activity — live status cards
Route::middleware(['auth'])->get('/agents/activity', [AgentActivityController::class, 'index'])->name('agents.activity');

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

// Schedules page — visible to all authenticated users
Route::get('/schedules', function () {
    return view('admin.schedules');
})->middleware(['auth'])->name('schedules');

// ─── Agent Shares — public client chat ───────────────────────────────────────
// GET page + password POST: web routes (need session)
// POST stream: handled in api.php (no CSRF)
Route::get('/a/{token}', [AgentShareController::class, 'show']);
Route::post('/a/{token}/password', [AgentShareController::class, 'verifyPassword'])
    ->withoutMiddleware('App\Http\Middleware\VerifyCsrfToken');

// Authenticated: manage shares
Route::middleware(['auth'])->group(function () {
    Route::get('/shares', [AgentShareController::class, 'index'])->name('shares.index');
});

// Admin: create/delete shares (any authenticated user can manage their own)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::post('/shares',           [AgentShareController::class, 'store'])->name('shares.store');
    Route::patch('/shares/{share}/toggle', [AgentShareController::class, 'toggle'])->name('shares.toggle');
    Route::delete('/shares/{share}', [AgentShareController::class, 'destroy'])->name('shares.destroy');
});

// Admin Portal
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
    Route::post('/users', [AdminController::class, 'createUser'])->name('admin.users.create');
    Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
    Route::patch('/users/{user}/toggle', [AdminController::class, 'toggleUser'])->name('admin.users.toggle');
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('admin.users.delete');
    Route::get('/conversations', [AdminController::class, 'conversations'])->name('admin.conversations');
    Route::get('/conversations/{conversation}', [AdminController::class, 'conversation'])->name('admin.conversation');
    Route::get('/schedules', function () { return view('admin.schedules'); })->name('admin.schedules');
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
