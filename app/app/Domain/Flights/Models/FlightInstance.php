<?php

namespace App\Domain\Flights\Models;

use App\Domain\Airports\Models\Airport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Stable logical identity of a flight on a specific airport board.
 *
 * Identity is the 4-tuple: (airport_id, board_type, flight_number, service_date_local).
 * This record is created once on first observation and never changed.
 *
 * All history (FlightChange, FlightSnapshot) anchors to this model's id so
 * that continuity is preserved regardless of state changes in FlightCurrent.
 */
class FlightInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_id',
        'board_type',
        'flight_number',
        'service_date_local',
        'airline_iata',
        'origin_iata',
        'destination_iata',
        'first_seen_at',
    ];

    protected $casts = [
        'service_date_local' => 'date',
        'first_seen_at'      => 'datetime',
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function current(): HasOne
    {
        return $this->hasOne(FlightCurrent::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(\App\Domain\Scraping\Models\FlightSnapshot::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(FlightChange::class);
    }
}
