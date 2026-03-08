<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the fuzzy fallback lookup in UpdateFlightCurrentStateJob::resolveExisting().
     *
     * The fuzzy query filters by (airport_id, board_type, service_date_local) with a
     * whereIn on both flight_number and operating_flight_number. The existing composite
     * index on (airport_id, board_type, service_date_local) handles the prefix; this
     * index covers operating_flight_number so the OR branch doesn't require a full scan.
     */
    public function up(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->index(['operating_flight_number', 'service_date_local'], 'fc_operating_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->dropIndex('fc_operating_date_idx');
        });
    }
};
