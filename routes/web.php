<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Redirect root to dashboard (or login if not authenticated)
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
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

require __DIR__.'/auth.php';
