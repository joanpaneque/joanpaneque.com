<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_event_indexes')) {
            Schema::create('calendar_event_indexes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('google_event_id');
                $table->string('google_recurring_event_id')->nullable();
                $table->string('title')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->string('color')->nullable();
                $table->boolean('is_all_day')->default(false);
                $table->boolean('is_recurring')->default(false);
                $table->json('recurrence')->nullable();
                $table->string('recurrence_rule')->nullable();
                $table->string('recurrence_frequency')->nullable();
                $table->unsignedInteger('recurrence_interval')->nullable();
                $table->json('recurrence_by_day')->nullable();
                $table->dateTimeTz('recurrence_until')->nullable();
                $table->unsignedInteger('recurrence_count')->nullable();
                $table->text('embedding_input')->nullable();
                $table->string('embedding_model')->nullable();
                $table->string('embedding_fingerprint', 64)->nullable();
                $table->timestampTz('embedding_generated_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'google_event_id']);
                $table->index(['user_id', 'start_date']);
                $table->index(['user_id', 'google_recurring_event_id']);
            });
        }

        if (Schema::hasColumn('calendar_event_indexes', 'embedding')) {
            return;
        }

        if ($this->pgVectorTypeExists()) {
            DB::statement('ALTER TABLE calendar_event_indexes ADD COLUMN embedding vector(1024)');

            return;
        }

        Schema::table('calendar_event_indexes', function (Blueprint $table) {
            $table->json('embedding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_indexes');
    }

    private function pgVectorTypeExists(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $result = DB::selectOne("select to_regtype('vector') is not null as exists");

        return $result !== null && (bool) $result->exists;
    }
};
