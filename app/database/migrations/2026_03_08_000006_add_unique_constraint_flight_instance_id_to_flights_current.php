<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces that FlightCurrent is a 1:1 projection of FlightInstance.
 *
 * Rationale: with identity separated into flight_instances, FlightCurrent
 * is now a mutable state view — there must be exactly one current-state row
 * per logical flight. Without this constraint, a race between two queue
 * workers processing the same flight would create duplicate FlightCurrent
 * rows even when the SELECT-then-INSERT is wrapped in a transaction, because
 * Postgres READ COMMITTED allows concurrent transactions to see different
 * snapshots before their respective inserts.
 *
 * The UNIQUE constraint is the last line of defence: a racing insert raises
 * UniqueConstraintViolationException, which UpdateFlightCurrentStateJob
 * catches and converts to a queue retry. On retry the row already exists and
 * the job updates it instead.
 *
 * The existing regular index 'fc_instance_idx' is dropped first to avoid a
 * redundant index — a unique index already implies the lookup semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            // Drop the regular index added by the previous migration — the
            // unique index below makes it redundant.
            $table->dropIndex('fc_instance_idx');

            $table->unique('flight_instance_id', 'fc_instance_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->dropUnique('fc_instance_unique');
            $table->index('flight_instance_id', 'fc_instance_idx');
        });
    }
};
