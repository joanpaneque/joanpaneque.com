<?php

use Database\Seeders\DentalHistoryFromNotesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Ejecuta DentalHistoryFromNotesSeeder (vacía historial, inserta fechas de a.txt, envía mensajes a Telegram).
     */
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => DentalHistoryFromNotesSeeder::class,
            '--force' => true,
        ]);
    }

    /**
     * No revierte el seeder (los datos insertados no se deshacen aquí).
     */
    public function down(): void
    {
        //
    }
};
