<?php

use App\Http\Controllers\NvidiaController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

// Chat API
Route::post('/chat', [NvidiaController::class, 'chat']);
Route::get('/agents', [NvidiaController::class, 'agents']);

// WhatsApp Business Webhook
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'webhook']);
