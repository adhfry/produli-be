<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sengaja di routes/web.php (bukan api.php): butuh session (middleware 'web') supaya
// Socialite bisa verifikasi state OAuth (CSRF) — bukan mode stateless (docs/planning/02 §6).
Route::prefix('auth/google')->group(function () {
    Route::get('redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    // Publik (Google redirect tanpa header auth) -- beda dari callback login di atas, ini pakai
    // Socialite stateless() + token cache sendiri (GoogleAccountLinkService), TIDAK butuh
    // session, tapi tetap di sini biar 1 tempat dengan flow Google lainnya.
    Route::get('link/callback', [GoogleAuthController::class, 'linkCallback'])->name('auth.google.link.callback');
});
