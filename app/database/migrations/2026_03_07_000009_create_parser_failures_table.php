<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parser_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scrape_job_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('failure_type', ['hard', 'soft']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('failure_details')->nullable();
            $table->enum('status', ['open', 'investigating', 'repaired', 'ignored'])->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['airport_source_id', 'status']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_failures');
    }
};
