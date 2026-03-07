<?php

namespace App\Http\Resources\Airports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'iata' => $this->iata,
            'icao' => $this->icao,
            'name' => $this->name,
            'city' => $this->city,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'sources' => AirportSourceResource::collection($this->whenLoaded('sources')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
