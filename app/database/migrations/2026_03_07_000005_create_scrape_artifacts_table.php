<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_job_id')->constrained()->cascadeOnDelete();
            $table->enum('artifact_type', ['html', 'screenshot', 'har', 'raw_response']);
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('artifact_type');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_artifacts');
    }
};
