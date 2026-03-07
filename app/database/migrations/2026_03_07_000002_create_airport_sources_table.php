<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airport_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->enum('board_type', ['departures', 'arrivals']);
            $table->enum('source_type', ['json_endpoint', 'html_table', 'playwright_table', 'playwright_cards', 'custom_playwright']);
            $table->string('url');
            // active_parser_version_id FK added after parser_versions table exists
            $table->unsignedBigInteger('active_parser_version_id')->nullable();
            $table->integer('scrape_interval_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['airport_id', 'board_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airport_sources');
    }
};
