<?php

namespace App\Http\Resources\Flights;

use App\Http\Resources\Airports\AirportResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->id,
            'canonical_key'                => $this->canonical_key,
            'board_type'                   => $this->board_type,
            'flight_number'                => $this->flight_number,
            'airline_iata'                 => $this->airline_iata,
            'airline_name'                 => $this->airline_name,
            'origin_iata'                  => $this->origin_iata,
            'destination_iata'             => $this->destination_iata,
            'service_date_local'           => $this->service_date_local?->toDateString(),
            'scheduled_departure_at_utc'   => $this->scheduled_departure_at_utc?->toIso8601String(),
            'estimated_departure_at_utc'   => $this->estimated_departure_at_utc?->toIso8601String(),
            'actual_departure_at_utc'      => $this->actual_departure_at_utc?->toIso8601String(),
            'scheduled_arrival_at_utc'     => $this->scheduled_arrival_at_utc?->toIso8601String(),
            'estimated_arrival_at_utc'     => $this->estimated_arrival_at_utc?->toIso8601String(),
            'actual_arrival_at_utc'        => $this->actual_arrival_at_utc?->toIso8601String(),
            'departure_terminal'           => $this->departure_terminal,
            'arrival_terminal'             => $this->arrival_terminal,
            'departure_gate'               => $this->departure_gate,
            'arrival_gate'                 => $this->arrival_gate,
            'baggage_belt'                 => $this->baggage_belt,
            'status_raw'                   => $this->status_raw,
            'status_normalized'            => $this->status_normalized,
            'delay_minutes'                => $this->delay_minutes,
            'is_active'                    => $this->is_active,
            'is_completed'                 => $this->is_completed,
            'last_seen_at'                 => $this->last_seen_at?->toIso8601String(),
            'last_changed_at'              => $this->last_changed_at?->toIso8601String(),
            'airport'                      => new AirportResource($this->whenLoaded('airport')),
        ];
    }
}
