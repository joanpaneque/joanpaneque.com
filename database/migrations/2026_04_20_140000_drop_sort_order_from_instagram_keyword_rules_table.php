<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instagram_keyword_rules')) {
            return;
        }
        if (! Schema::hasColumn('instagram_keyword_rules', 'sort_order')) {
            return;
        }
        Schema::table('instagram_keyword_rules', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instagram_keyword_rules')) {
            return;
        }
        if (Schema::hasColumn('instagram_keyword_rules', 'sort_order')) {
            return;
        }
        Schema::table('instagram_keyword_rules', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });
    }
};
