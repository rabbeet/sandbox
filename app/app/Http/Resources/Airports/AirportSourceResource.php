<?php

namespace App\Http\Resources\Airports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirportSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airport_id' => $this->airport_id,
            'board_type' => $this->board_type,
            'source_type' => $this->source_type,
            'url' => $this->url,
            'active_parser_version_id' => $this->active_parser_version_id,
            'scrape_interval_minutes' => $this->scrape_interval_minutes,
            'is_active' => $this->is_active,
            'airport' => new AirportResource($this->whenLoaded('airport')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
