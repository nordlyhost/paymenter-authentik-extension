<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\Authentik\Http\Controllers\AuthentikController;

/*
 * Authentik OIDC routes.
 *
 * These intentionally use the /oauth/authentik path. Paymenter core registers a
 * wildcard `/oauth/{provider}` (oauth.redirect) and `/oauth/{provider}/callback`
 * (oauth.handle). Because Laravel matches routes in registration order, these
 * extension routes must be registered before the core wildcard to win. They are
 * registered from the extension's boot() (AppServiceProvider boot phase), which
 * runs before core web routes are loaded. Verify with `php artisan route:list`
 * after deploy: `oauth.authentik.*` must resolve here, not to oauth.handle.
 */
Route::middleware(['web'])->group(function () {
    Route::get('/oauth/authentik', [AuthentikController::class, 'redirect'])
        ->name('oauth.authentik.redirect');

    Route::get('/oauth/authentik/callback', [AuthentikController::class, 'callback'])
        ->name('oauth.authentik.callback');

    // Break-glass: reveal the native email/password form on the login page when
    // the Authentik-only login is enabled (admin fallback if Authentik is down).
    // Uses a session flag so the form survives Livewire re-renders, unlike a
    // query string. Has no effect unless the override login view is active.
    Route::get('/login/local', function () {
        session(['authentik_break_glass' => true]);

        return redirect()->route('login');
    })->name('oauth.authentik.break_glass');
});
