<?php

use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\EmailSendController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\NvidiaController;
use App\Http\Controllers\WhatsAppController;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Route;

// ─── PUBLIC (unauthenticated) ──────────────────────────────────────────────

// Company profile JSON — readable by AI agents, crawlers, and integrations
Route::get('/company-profile', function () {
    return response()->json(PartYardProfileService::toPublicJson(), 200, [
        'Cache-Control' => 'public, max-age=3600',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->middleware('throttle:60,1');

// WhatsApp webhook must remain public for Meta to verify
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'webhook'])
    ->middleware('throttle:120,1');

// ─── AUTHENTICATED + RATE LIMITED ─────────────────────────────────────────
Route::middleware(['auth:web', 'throttle:60,1'])->group(function () {

    // Chat API — SSE streaming (fixes Cloudflare 504 timeouts)
    Route::post('/chat', [NvidiaController::class, 'chatStream']);

    // Agent list
    Route::get('/agents', [NvidiaController::class, 'agents']);

    // Conversation history — only own sessions
    Route::get('/history/{sessionId}', [NvidiaController::class, 'history']);

    // Reports — save agent output
    Route::post('/reports', [ReportController::class, 'store']);

    // Discoveries — save patent/paper from agent
    Route::post('/discoveries', [DiscoveryController::class, 'store']);

    // Email sending
    Route::post('/email/send', [EmailSendController::class, 'send'])
        ->middleware('throttle:10,1'); // max 10 emails per minute per user

});

// ─── ADMIN ONLY ────────────────────────────────────────────────────────────
Route::middleware(['auth:web', 'admin', 'throttle:30,1'])->group(function () {

    // RAG document upload — admin only
    Route::post('/documents', [NvidiaController::class, 'uploadDocument']);

});
