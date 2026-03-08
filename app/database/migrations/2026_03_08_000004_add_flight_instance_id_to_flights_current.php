<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds flight_instance_id to flights_current, making it a state projection
 * of the stable logical identity in flight_instances.
 *
 * canonical_key is kept for now as a human-readable label and for API
 * response compatibility, but it is no longer the lookup mechanism.
 * The source of truth for identity is flight_instance_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->foreignId('flight_instance_id')
                ->nullable()
                ->after('id')
                ->constrained('flight_instances')
                ->cascadeOnDelete();

            $table->index('flight_instance_id', 'fc_instance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->dropForeign(['flight_instance_id']);
            $table->dropIndex('fc_instance_idx');
            $table->dropColumn('flight_instance_id');
        });
    }
};
