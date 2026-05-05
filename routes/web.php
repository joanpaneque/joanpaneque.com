<?php

use App\Http\Controllers\CalendarChatController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Nebula\GoogleCalendarController;
use App\Http\Controllers\Nebula\InstagramKeywordRuleController;
use App\Http\Controllers\NebulaController;
use App\Http\Controllers\PersonalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/eisenhower', function () {
    return Inertia::render('Eisenhower');
})->name('eisenhower');

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
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.auth.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.auth.callback');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
    Route::get('/calendar/colors', [CalendarController::class, 'colors'])->name('calendar.colors');
    Route::get('/calendar/chats', [CalendarChatController::class, 'index'])->name('calendar.chats.index');
    Route::post('/calendar/chats', [CalendarChatController::class, 'store'])->name('calendar.chats.store');
    Route::get('/calendar/chats/{chat}', [CalendarChatController::class, 'show'])->name('calendar.chats.show');
    Route::delete('/calendar/chats/{chat}', [CalendarChatController::class, 'destroy'])->name('calendar.chats.destroy');
    Route::post('/calendar/chats/{chat}/messages', [CalendarChatController::class, 'message'])->name('calendar.chats.messages');
    Route::post('/calendar/chats/{chat}/messages/{message}/edit', [CalendarChatController::class, 'edit'])->name('calendar.chats.messages.edit');
    Route::post('/calendar/chats/{chat}/messages/{message}/revert', [CalendarChatController::class, 'revert'])->name('calendar.chats.messages.revert');
    Route::get('/calendar/chats/{chat}/stream', [CalendarChatController::class, 'stream'])->name('calendar.chats.stream');
    Route::post('/calendar/events', [CalendarController::class, 'store'])->name('calendar.store');
    Route::patch('/calendar/events/{eventId}', [CalendarController::class, 'update'])->name('calendar.update');
    Route::patch('/calendar/events/{eventId}/color', [CalendarController::class, 'updateColor'])->name('calendar.update-color');
    Route::delete('/calendar/events/{eventId}', [CalendarController::class, 'destroy'])->name('calendar.destroy');

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
