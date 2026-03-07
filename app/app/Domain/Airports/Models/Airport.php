<?php

namespace App\Domain\Airports\Models;

use App\Domain\Flights\Models\FlightCurrent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airport extends Model
{
    use HasFactory;

    protected $fillable = [
        'iata',
        'icao',
        'name',
        'city',
        'country',
        'timezone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(AirportSource::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(FlightCurrent::class);
    }
}
