<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * flight_instances is the stable logical identity layer for flights.
 *
 * Identity is defined by the 4-tuple:
 *   (airport_id, board_type, flight_number, service_date_local)
 *
 * Decisions:
 *   - flight_number is the display/marketing number — what the board shows.
 *     It is stable across scrapes for a given airport source configuration.
 *   - scheduled_time is intentionally excluded from identity. It is mutable
 *     state (retimes exist) and belongs in flights_current, not here.
 *   - service_date_local is the operational date, derived by the normaliser
 *     from the row's local scheduled time when not explicitly provided by
 *     the parser. This makes midnight boundary a system guarantee, not a
 *     parser discipline requirement.
 *   - All history (flight_changes, flight_snapshots) anchors to this table's
 *     id so continuity survives any state change in flights_current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->enum('board_type', ['departures', 'arrivals']);
            $table->string('flight_number', 20);
            $table->date('service_date_local');
            $table->string('airline_iata', 3)->nullable();
            $table->string('origin_iata', 3)->nullable();
            $table->string('destination_iata', 3)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            // Primary identity constraint: one flight_instance per flight per day per board.
            $table->unique(
                ['airport_id', 'board_type', 'flight_number', 'service_date_local'],
                'fi_identity_unique'
            );

            $table->index(['airport_id', 'board_type', 'service_date_local'], 'fi_board_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_instances');
    }
};
