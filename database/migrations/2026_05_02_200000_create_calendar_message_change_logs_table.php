<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_message_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_chat_message_id')->constrained()->cascadeOnDelete();
            $table->json('changes');
            $table->timestamp('reverted_at')->nullable();
            $table->json('revert_result')->nullable();
            $table->timestamps();

            $table->index(['calendar_chat_message_id', 'reverted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_message_change_logs');
    }
};
