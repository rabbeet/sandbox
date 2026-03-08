<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add triggered_by to scrape_jobs.
 *
 * Values: 'scheduler' (default, set by ScheduleScrapes command) or 'manual'
 * (set by the POST /api/sources/{source}/scrape admin endpoint).
 * Nullable for backward compatibility with existing rows created before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->string('triggered_by', 32)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropColumn('triggered_by');
        });
    }
};
