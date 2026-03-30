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
        Schema::table('teeth_prompts', function (Blueprint $table) {
            $table->string('phase', 20)->nullable()->after('grace_period_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teeth_prompts', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
