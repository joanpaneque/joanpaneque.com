<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Obsoleta: la limpieza de Google Calendar está en 2026_05_02_180000_remove_google_calendar_from_database.
 * Se mantiene el archivo para no romper el historial de migrate en entornos que ya la ejecutaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
