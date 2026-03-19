<?php

use App\Http\Controllers\EmailSendController;
use App\Http\Controllers\NvidiaController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

// ─── PUBLIC (unauthenticated) ──────────────────────────────────────────────
// WhatsApp webhook must remain public for Meta to verify
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'webhook'])
    ->middleware('throttle:120,1');

// ─── AUTHENTICATED + RATE LIMITED ─────────────────────────────────────────
Route::middleware(['auth', 'throttle:60,1'])->group(function () {

    // Chat API (Memory + RAG + Multimodal)
    Route::post('/chat', [NvidiaController::class, 'chat']);

    // Agent list
    Route::get('/agents', [NvidiaController::class, 'agents']);

    // Conversation history — only own sessions
    Route::get('/history/{sessionId}', [NvidiaController::class, 'history']);

    // Email sending
    Route::post('/email/send', [EmailSendController::class, 'send'])
        ->middleware('throttle:10,1'); // max 10 emails per minute per user

});

// ─── ADMIN ONLY ────────────────────────────────────────────────────────────
Route::middleware(['auth', 'admin', 'throttle:30,1'])->group(function () {

    // RAG document upload — admin only
    Route::post('/documents', [NvidiaController::class, 'uploadDocument']);

});
