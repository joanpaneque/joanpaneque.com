<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Nuevo chat');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('calendar_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->timestamps();

            $table->index(['calendar_chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_chat_messages');
        Schema::dropIfExists('calendar_chats');
    }
};
