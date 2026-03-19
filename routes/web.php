<?php

use App\Http\Controllers\AdminController;
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

require __DIR__.'/auth.php';
