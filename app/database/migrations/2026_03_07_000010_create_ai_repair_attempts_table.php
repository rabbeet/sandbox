<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_repair_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_failure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_parser_version_id')->nullable()->constrained('parser_versions')->nullOnDelete();
            $table->enum('status', [
                'pending', 'generating', 'scoring', 'review',
                'approved', 'rejected', 'canary', 'activated', 'failed'
            ])->default('pending');
            $table->decimal('score', 5, 2)->nullable();
            $table->jsonb('score_details')->nullable();
            $table->unsignedSmallInteger('canary_runs')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['parser_failure_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_repair_attempts');
    }
};
