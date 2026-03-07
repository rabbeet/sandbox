<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_current_id')->constrained('flights_current')->cascadeOnDelete();
            $table->foreignId('scrape_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field_name', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['flight_current_id', 'changed_at']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_changes');
    }
};
