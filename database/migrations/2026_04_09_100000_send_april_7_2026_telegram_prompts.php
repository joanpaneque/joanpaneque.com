<?php

use App\Services\PhoneAwayFairy;
use App\Services\ToothFairy;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

/**
 * Envía a Telegram los cuatro recordatorios del día (mañana, mediodía, noche de dientes + móvil lejos),
 * con prompt_sent_at como si hubieran salido el 7 de abril de 2026 (Europe/Madrid), para poder
 * responderlos desde el chat. Si no hay TELEGRAM_CHAT_ID, no hace nada.
 */
return new class extends Migration
{
    private const TZ = 'Europe/Madrid';

    /** Segundos entre envíos (evita 429 en Telegram). */
    private const TELEGRAM_GAP_SECONDS = 1;

    public function up(): void
    {
        // if (! config('services.telegram.chat_id')) {
        //     return;
        // }

        // $day = Carbon::create(2026, 4, 7, 0, 0, 0, self::TZ);

        // $toothFairy = app(ToothFairy::class);
        // $phoneAway = app(PhoneAwayFairy::class);

        // $morning = $toothFairy->sendBrushingPrompt(
        //     20,
        //     'Te has lavado los dientes por la mañana?',
        //     'morning',
        // );
        // $morning->update(['prompt_sent_at' => $day->copy()->setTime(7, 45, 0)]);
        // $this->sleepBetweenSends();

        // $midday = $toothFairy->sendBrushingPrompt(
        //     4 * 60,
        //     'Te has lavado los dientes al mediodía?',
        //     'midday',
        // );
        // $midday->update(['prompt_sent_at' => $day->copy()->setTime(13, 0, 0)]);
        // $this->sleepBetweenSends();

        // $night = $toothFairy->sendBrushingPrompt(
        //     4 * 60,
        //     'Te has lavado los dientes por la noche?',
        //     'night',
        // );
        // $night->update(['prompt_sent_at' => $day->copy()->setTime(20, 0, 0)]);
        // $this->sleepBetweenSends();

        // $phone = $phoneAway->sendPrompt(4 * 60);
        // $phone->update(['prompt_sent_at' => $day->copy()->setTime(20, 0, 1)]);
    }

    public function down(): void
    {
        // Los mensajes ya están en Telegram; no se revierten.
    }

    private function sleepBetweenSends(): void
    {
        sleep(self::TELEGRAM_GAP_SECONDS);
    }
};
