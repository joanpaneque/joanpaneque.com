<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teeth_cleanings', 'completed')) {
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->boolean('completed')->default(true)->after('delayed');
            });
        }

        if (Schema::hasColumn('teeth_cleanings', 'delay_reason') && ! Schema::hasColumn('teeth_cleanings', 'response_note')) {
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->text('response_note')->nullable()->after('completed');
            });
            DB::statement('UPDATE teeth_cleanings SET response_note = delay_reason WHERE delay_reason IS NOT NULL');
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->dropColumn('delay_reason');
            });
        } elseif (! Schema::hasColumn('teeth_cleanings', 'response_note')) {
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->text('response_note')->nullable()->after('completed');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('teeth_cleanings', 'response_note')) {
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->text('delay_reason')->nullable()->after('delayed');
            });
            DB::statement('UPDATE teeth_cleanings SET delay_reason = response_note WHERE response_note IS NOT NULL');
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->dropColumn('response_note');
            });
        }

        if (Schema::hasColumn('teeth_cleanings', 'completed')) {
            Schema::table('teeth_cleanings', function (Blueprint $table) {
                $table->dropColumn('completed');
            });
        }
    }
};
