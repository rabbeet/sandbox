<?php

namespace App\Domain\Flights\Models;

use App\Domain\Airports\Models\Airport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightCurrent extends Model
{
    use HasFactory;

    protected $table = 'flights_current';

    protected $fillable = [
        'airport_id',
        'canonical_key',
        'board_type',
        'flight_number',
        'airline_iata',
        'airline_name',
        'origin_iata',
        'destination_iata',
        'service_date_local',
        'scheduled_departure_at_utc',
        'estimated_departure_at_utc',
        'actual_departure_at_utc',
        'scheduled_arrival_at_utc',
        'estimated_arrival_at_utc',
        'actual_arrival_at_utc',
        'departure_terminal',
        'arrival_terminal',
        'departure_gate',
        'arrival_gate',
        'baggage_belt',
        'status_raw',
        'status_normalized',
        'delay_minutes',
        'is_active',
        'is_completed',
        'last_seen_at',
        'last_changed_at',
    ];

    protected $casts = [
        'service_date_local' => 'date',
        'scheduled_departure_at_utc' => 'datetime',
        'estimated_departure_at_utc' => 'datetime',
        'actual_departure_at_utc' => 'datetime',
        'scheduled_arrival_at_utc' => 'datetime',
        'estimated_arrival_at_utc' => 'datetime',
        'actual_arrival_at_utc' => 'datetime',
        'is_active' => 'boolean',
        'is_completed' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_changed_at' => 'datetime',
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(FlightChange::class, 'flight_current_id');
    }
}
