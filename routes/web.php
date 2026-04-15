<?php

use App\Http\Controllers\Admin\GoogleCalendarController;
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
});

Route::get('/admin/login', function () {
    return view('admin.login');
})->name('admin.login');

Route::post('/admin/login', function () {
    $secret = config('services.google.admin_secret');
    if (empty($secret) || request()->input('admin_secret') !== $secret) {
        return back()->withErrors(['admin_secret' => 'Contrasena incorrecta.']);
    }
    request()->session()->put('admin_authenticated', true);

    return redirect()->intended(route('admin.google-calendar.index'));
})->name('admin.login.store');

Route::middleware('admin')->prefix('admin/google-calendar')->name('admin.google-calendar.')->group(function () {
    Route::get('/', [GoogleCalendarController::class, 'index'])->name('index');
    Route::get('/connect', [GoogleCalendarController::class, 'connect'])->name('connect');
    Route::get('/callback', [GoogleCalendarController::class, 'callback'])->name('callback');
    Route::get('/debug', [GoogleCalendarController::class, 'debug'])->name('debug');
});
