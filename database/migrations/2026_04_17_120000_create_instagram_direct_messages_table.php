<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_direct_messages', function (Blueprint $table) {
            $table->id();
            $table->string('peer_ig_user_id')->index();
            $table->string('direction', 16);
            $table->text('body');
            $table->string('meta_message_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_direct_messages');
    }
};
