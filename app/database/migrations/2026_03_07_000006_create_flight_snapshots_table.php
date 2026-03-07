<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_job_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_key');
            $table->jsonb('raw_payload');
            $table->jsonb('normalized_payload')->nullable();
            $table->timestamps();

            $table->index('canonical_key');
            $table->index(['scrape_job_id', 'canonical_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_snapshots');
    }
};
