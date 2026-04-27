<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    // POST forgot-password — Laravel's broker has its own 60s/email
    // throttle (config/auth.php → passwords.users.throttle). On top of
    // that we add a per-IP request rate so a botnet can't enumerate
    // emails by spraying. 30/min/IP keeps a real user calm even with
    // multiple "Resend" clicks.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    // Email verification — bumped from Laravel's default 6/min to 30/min.
    // The default was triggering 429 for legitimate users:
    //   • Click the link in their email → tab opens
    //   • Refresh the success page (or the browser auto-refreshes)
    //   • Click the link a second time from the email
    //   • Multiple users in the same office IP share the bucket
    // Six attempts evaporate in seconds. 30/min keeps the abuse-mitigation
    // intent (the route is `signed`, so an attacker would need a leaked
    // URL anyway) while not punishing humans.
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:30,1'])
        ->name('verification.verify');

    // Resend verification email — same reasoning. Default 6/min was too
    // tight when a user clicks "Resend" twice in a row "to make sure".
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
