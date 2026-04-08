<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teeth_cleanings', function (Blueprint $table) {
            $table->string('phase')->nullable()->after('telegram_message_id');
            $table->text('delay_reason')->nullable()->after('delayed');
        });
    }

    public function down(): void
    {
        Schema::table('teeth_cleanings', function (Blueprint $table) {
            $table->dropColumn(['phase', 'delay_reason']);
        });
    }
};
