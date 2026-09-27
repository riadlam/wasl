<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CampaignSlotEditLinkController;
use App\Http\Controllers\SocialApiCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/socialapi/callback', SocialApiCallbackController::class)
    ->name('socialapi.callback');

Route::get('/media/messages/{message}', [\App\Http\Controllers\Api\MessageMediaController::class, 'show'])
    ->middleware(['throttle:180,1'])
    ->name('messages.media');

Route::get('/media/posts/{id}', [\App\Http\Controllers\Api\PostMediaController::class, 'show'])
    ->middleware(['throttle:180,1'])
    ->name('posts.media');

Route::middleware(['auth', 'shop'])->group(function () {
    // Signed Telegram → Wasl deep link (opens campaign detail + Edit modal).
    Route::get('/go/campaigns/slots/{slot}/edit', CampaignSlotEditLinkController::class)
        ->middleware('signed')
        ->name('campaigns.slot.edit-link');

    // SPA workspace — all feature paths load the same shell; React owns the route.
    Route::get('/space/{path?}', function () {
        return view('space', ['start' => 'inbox']);
    })->where('path', '.*')->name('space');

    // Legacy bookmark → canonical path
    Route::redirect('/workflow', '/space/workflows');
    Route::redirect('/workflows', '/space/workflows');
});

Route::get('/admin', function () {
    return view('admin');
})->middleware(['auth', 'super_admin'])->name('admin');
