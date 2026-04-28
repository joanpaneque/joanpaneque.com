<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_keyword_rule_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_keyword_rule_id')
                ->constrained('instagram_keyword_rules')
                ->cascadeOnDelete();
            $table->string('keyword', 500);
            /** @var list<float> vector OpenRouter / text-embedding-3-small */
            $table->json('embedding');
            $table->timestamps();

            $table->unique(['instagram_keyword_rule_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_keyword_rule_embeddings');
    }
};
