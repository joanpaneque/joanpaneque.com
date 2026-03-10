<?php

use App\Http\Controllers\Admin\GoogleCalendarController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
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
