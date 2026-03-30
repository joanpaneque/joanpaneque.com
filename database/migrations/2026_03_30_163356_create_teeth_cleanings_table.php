<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teeth_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id');
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('prompt_sent_at');
            $table->unsignedInteger('grace_period_minutes');
            $table->timestamps();

            $table->index('telegram_chat_id');
        });

        Schema::create('teeth_cleanings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('telegram_chat_id');
            $table->timestamp('prompt_sent_at');
            $table->timestamp('answered_at');
            $table->unsignedInteger('grace_period_minutes');
            $table->boolean('delayed')->default(false);
            $table->timestamps();

            $table->index(['telegram_user_id', 'answered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teeth_cleanings');
        Schema::dropIfExists('teeth_prompts');
    }
};
