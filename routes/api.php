<?php

use App\Http\Controllers\EmailController;
use App\Http\Controllers\EmailSendController;
use App\Http\Controllers\NvidiaController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

// Chat API (with Memory + RAG + Multimodal)
Route::post('/chat', [NvidiaController::class, 'chat']);
Route::get('/agents', [NvidiaController::class, 'agents']);
Route::get('/history/{sessionId}', [NvidiaController::class, 'history']);

// RAG Knowledge Base
Route::post('/documents', [NvidiaController::class, 'uploadDocument']);

// Email Sending
Route::post('/email/send', [EmailSendController::class, 'send']);

// WhatsApp Business Webhook
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'webhook']);
