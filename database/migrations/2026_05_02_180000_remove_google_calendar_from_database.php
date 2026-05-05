<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Quita datos de BD ligados al antiguo flujo OAuth / Google Calendar:
 * - tabla google_tokens
 * - filas en bookings cuyo event_id era el ID devuelto por la API de Calendar (no UUID del sistema actual)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('google_tokens');

        Booking::query()->chunkById(100, function ($bookings): void {
            foreach ($bookings as $booking) {
                if (! Str::isUuid($booking->event_id)) {
                    $booking->delete();
                }
            }
        });
    }

    public function down(): void
    {
        //
    }
};
