<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\InstagramWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/availability', [BookingController::class, 'availability']);
Route::post('/book', [BookingController::class, 'book']);

Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

Route::match(['get', 'post'], '/instagram/webhook', [InstagramWebhookController::class, 'webhook'])
    ->name('instagram.webhook');
