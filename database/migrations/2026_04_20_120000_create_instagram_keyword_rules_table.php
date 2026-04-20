<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_keyword_rules', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            /** @var array<int, string> Keywords: comentario normalizado igual (trim + mayúsculas ignoradas). */
            $table->json('keywords');
            /** @var array<int, string> Respuestas públicas al comentario; una al azar. */
            $table->json('comment_reply_variants');
            /** Texto del primer DM (con quick replies). Vacío = solo respuesta pública. */
            $table->text('dm_phase1_text')->nullable();
            /**
             * Botones quick reply: title (≤20 en IG), payload para webhook.
             *
             * @var array<int, array{title: string, payload: string}>
             */
            $table->json('dm_quick_replies')->nullable();
            /** Respuestas al pulsar quick reply (o texto/payload coincidente); una al azar. */
            $table->json('dm_phase2_reply_variants')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_keyword_rules');
    }
};
