<?php

use App\Http\Controllers\Nebula\GoogleCalendarController;
use App\Http\Controllers\Nebula\InstagramKeywordRuleController;
use App\Http\Controllers\NebulaController;
use App\Http\Controllers\PersonalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::view('/meta-privacy', 'meta-privacy', [
    'controllerName' => config('services.meta.privacy_controller') ?: config('app.name'),
    'lastUpdated' => 'April 15, 2026',
])->name('meta.privacy');

Route::view('/meta-terms', 'meta-terms', [
    'lastUpdated' => 'April 15, 2026',
])->name('meta.terms');

Route::view('/meta-data', 'meta-data', [
    'lastUpdated' => 'April 15, 2026',
])->name('meta.data-deletion');

Route::post('/meta-data', function () {
    return redirect()->route('meta.data-deletion')->with('meta_data_submitted', true);
})->name('meta.data-deletion.store');

Route::get('/personal/login', [PersonalController::class, 'showLogin'])->name('personal.login');
Route::post('/personal/login', [PersonalController::class, 'login'])->name('personal.login.store');

Route::middleware('auth')->group(function () {
    Route::get('/personal', [PersonalController::class, 'dashboard'])->name('personal.dashboard');
    Route::post('/personal/logout', [PersonalController::class, 'logout'])->name('personal.logout');

    Route::prefix('nebula')->name('nebula.')->group(function () {
        Route::get('/', [NebulaController::class, 'dashboard'])->name('dashboard');

        Route::get('/instagram-rules', [InstagramKeywordRuleController::class, 'index'])->name('instagram-rules.index');
        Route::get('/instagram-rules/create', [InstagramKeywordRuleController::class, 'create'])->name('instagram-rules.create');
        Route::post('/instagram-rules', [InstagramKeywordRuleController::class, 'store'])->name('instagram-rules.store');
        Route::get('/instagram-rules/{rule}/edit', [InstagramKeywordRuleController::class, 'edit'])->name('instagram-rules.edit');
        Route::put('/instagram-rules/{rule}', [InstagramKeywordRuleController::class, 'update'])->name('instagram-rules.update');
        Route::delete('/instagram-rules/{rule}', [InstagramKeywordRuleController::class, 'destroy'])->name('instagram-rules.destroy');

        Route::prefix('google-calendar')->name('google-calendar.')->group(function () {
            Route::get('/', [GoogleCalendarController::class, 'index'])->name('index');
            Route::get('/connect', [GoogleCalendarController::class, 'connect'])->name('connect');
            Route::get('/callback', [GoogleCalendarController::class, 'callback'])->name('callback');
            Route::get('/debug', [GoogleCalendarController::class, 'debug'])->name('debug');
        });
    });
});
