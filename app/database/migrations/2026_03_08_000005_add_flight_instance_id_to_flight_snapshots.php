<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchors flight_snapshots to flight_instances so that the full observation
 * history of a flight remains queryable via the stable identity id,
 * independent of any canonical_key string drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_snapshots', function (Blueprint $table) {
            $table->foreignId('flight_instance_id')
                ->nullable()
                ->after('scrape_job_id')
                ->constrained('flight_instances')
                ->nullOnDelete();

            $table->index('flight_instance_id', 'fs_instance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flight_snapshots', function (Blueprint $table) {
            $table->dropForeign(['flight_instance_id']);
            $table->dropIndex('fs_instance_idx');
            $table->dropColumn('flight_instance_id');
        });
    }
};
