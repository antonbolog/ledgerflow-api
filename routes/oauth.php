<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Modules\Merchant\Http\Controllers\MerchantAuthorizationController;
use Modules\Merchant\Http\Controllers\MerchantLoginController;
use Modules\Merchant\Http\Middleware\EndMerchantAuthorizationSession;
use Modules\Merchant\Http\Middleware\RequireProofKeyForCodeExchange;

// No `web` group: a session or CSRF token here would be a cookie credential on a server-to-server
// endpoint, which the resource API must never learn to accept.
Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware('throttle:oauth-token')
    ->name('oauth.token');

Route::middleware('web')->group(function (): void {
    // The session-ending middleware wraps the rest, so a rejected request also spends the session.
    Route::get('/oauth/authorize', [MerchantAuthorizationController::class, 'authorize'])
        ->middleware([EndMerchantAuthorizationSession::class, RequireProofKeyForCodeExchange::class])
        ->name('oauth.authorize');

    // Laravel's guest redirect resolves the name `login` and nothing else.
    Route::get('/login', [MerchantLoginController::class, 'show'])->name('login');

    Route::post('/login', [MerchantLoginController::class, 'store'])
        ->middleware('throttle:merchant-login')
        ->name('login.attempt');
});
