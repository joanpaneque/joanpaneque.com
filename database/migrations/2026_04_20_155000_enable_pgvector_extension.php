<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pgvector: CREATE EXTENSION vector (solo PostgreSQL).
 *
 * Opcional: las embeddings de reglas Instagram se guardan en JSON y no dependen del tipo native vector.
 * Si el servidor PostgreSQL no incluye pgvector (p. ej. imagen oficial postgres sin paquete vector),
 * esta migración se omite en lugar de fallar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $available = DB::selectOne(
            <<<'SQL'
            select exists(
                select 1 from pg_available_extensions where name = 'vector'
            ) as available
            SQL
        );

        if ($available === null || ! (bool) $available->available) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
