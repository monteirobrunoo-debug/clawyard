<?php

use App\Http\Controllers\NvidiaController;
use Illuminate\Support\Facades\Route;

Route::post('/chat', [NvidiaController::class, 'chat']);
