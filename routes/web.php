<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AgentActivityController;
use App\Http\Controllers\SapTableController;
use Illuminate\Support\Facades\Route;

// Kyber-1024 key management UI
Route::get('/keys', function () {
    return view('keys.manage');
})->middleware('auth');

// Kyber-1024 decrypt page — public, no login required
Route::get('/decrypt', function () {
    return view('keys.decrypt');
});

// Outlook Add-in task panes
Route::get('/outlook-addin/read',    function () { return response()->file(public_path('outlook-addin/read.html')); });
Route::get('/outlook-addin/compose', function () { return response()->file(public_path('outlook-addin/compose.html')); });

// Redirect root to dashboard (or login if not authenticated)
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

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

// SAP Documents — interactive table (Richard SAP)
Route::middleware(['auth'])->group(function () {
    Route::get('/sap/documents', [SapTableController::class, 'index'])->name('sap.documents');
});

// Schedules page — visible to all authenticated users
Route::get('/schedules', function () {
    return view('admin.schedules');
})->middleware(['auth'])->name('schedules');

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
