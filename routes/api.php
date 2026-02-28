<?php

use App\Http\Controllers\Api\BookingController;
use Illuminate\Support\Facades\Route;

Route::get('/availability', [BookingController::class, 'availability']);
Route::post('/book', [BookingController::class, 'book']);
