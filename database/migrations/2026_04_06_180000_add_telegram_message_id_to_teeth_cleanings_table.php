<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teeth_cleanings', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('telegram_chat_id');
            $table->index('telegram_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('teeth_cleanings', function (Blueprint $table) {
            $table->dropIndex(['telegram_message_id']);
            $table->dropColumn('telegram_message_id');
        });
    }
};
