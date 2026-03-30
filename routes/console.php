<?php

use App\Services\PhoneAwayFairy;
use App\Services\ToothFairy;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Recordatorio: en el servidor debe ejecutarse cada minuto:
| * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
*/
Schedule::call(function () {
    app(ToothFairy::class)->sendBrushingPrompt(
        20,
        'Te has lavado los dientes por la mañana?',
        'morning',
    );
})->dailyAt('07:45')->timezone('Europe/Madrid');

Schedule::call(function () {
    app(ToothFairy::class)->sendBrushingPrompt(
        4 * 60,
        'Te has lavado los dientes al mediodía?',
        'midday',
    );
})->dailyAt('13:00')->timezone('Europe/Madrid');

Schedule::call(function () {
    app(ToothFairy::class)->sendBrushingPrompt(
        4 * 60,
        'Te has lavado los dientes por la noche?',
        'night',
    );
    app(PhoneAwayFairy::class)->sendPrompt(4 * 60);
})->dailyAt('20:00')->timezone('Europe/Madrid');

Schedule::call(function () {
    app(ToothFairy::class)->expireStalePrompts();
    app(PhoneAwayFairy::class)->expireStalePrompts();
})->everyFiveMinutes();
