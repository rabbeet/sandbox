<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights_current', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_key')->unique();
            $table->enum('board_type', ['departures', 'arrivals']);
            $table->string('flight_number', 16);
            $table->string('airline_iata', 3)->nullable();
            $table->string('airline_name')->nullable();
            $table->string('origin_iata', 3)->nullable();
            $table->string('destination_iata', 3)->nullable();
            $table->date('service_date_local');
            $table->timestamp('scheduled_departure_at_utc')->nullable();
            $table->timestamp('estimated_departure_at_utc')->nullable();
            $table->timestamp('actual_departure_at_utc')->nullable();
            $table->timestamp('scheduled_arrival_at_utc')->nullable();
            $table->timestamp('estimated_arrival_at_utc')->nullable();
            $table->timestamp('actual_arrival_at_utc')->nullable();
            $table->string('departure_terminal', 16)->nullable();
            $table->string('arrival_terminal', 16)->nullable();
            $table->string('departure_gate', 16)->nullable();
            $table->string('arrival_gate', 16)->nullable();
            $table->string('baggage_belt', 16)->nullable();
            $table->string('status_raw')->nullable();
            $table->string('status_normalized', 64)->nullable();
            $table->integer('delay_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->index(['airport_id', 'board_type', 'service_date_local']);
            $table->index(['flight_number', 'service_date_local']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flights_current');
    }
};
