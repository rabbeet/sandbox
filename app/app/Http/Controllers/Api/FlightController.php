<?php

namespace App\Http\Controllers\Api;

use App\Domain\Airports\Models\Airport;
use App\Domain\Flights\Models\FlightCurrent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flights\FlightResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FlightController extends Controller
{
    /**
     * GET /api/flights/search?flight=TK123
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'flight'      => 'required|string|min:2|max:20',
            'date'        => 'nullable|date',
            'board_type'  => 'nullable|in:departures,arrivals',
        ]);

        $term      = strtoupper(trim($request->input('flight')));
        $date      = $request->input('date');
        $boardType = $request->input('board_type');

        $flights = FlightCurrent::with('airport')
            ->where(function ($q) use ($term) {
                // Use LOWER() for case-insensitive search (works in both PostgreSQL and SQLite)
                $lower = strtolower($term);
                $q->whereRaw('LOWER(flight_number) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(canonical_key) LIKE ?', ["%{$lower}%"]);
            })
            ->when($date, fn ($q) => $q->where('service_date_local', $date))
            ->when($boardType, fn ($q) => $q->where('board_type', $boardType))
            ->where('is_active', true)
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        return FlightResource::collection($flights);
    }

    /**
     * GET /api/flights/{id}
     */
    public function show(FlightCurrent $flight): FlightResource
    {
        return new FlightResource($flight->load('airport'));
    }

    /**
     * GET /api/airports/{iata}/departures
     */
    public function departures(Request $request, string $iata): AnonymousResourceCollection
    {
        return $this->board($request, $iata, 'departures');
    }

    /**
     * GET /api/airports/{iata}/arrivals
     */
    public function arrivals(Request $request, string $iata): AnonymousResourceCollection
    {
        return $this->board($request, $iata, 'arrivals');
    }

    /**
     * GET /api/disruptions — active delayed/cancelled/diverted flights
     */
    public function disruptions(Request $request): AnonymousResourceCollection
    {
        $flights = FlightCurrent::with('airport')
            ->whereIn('status_normalized', ['delayed', 'cancelled', 'diverted'])
            ->where('is_active', true)
            ->whereNull('actual_departure_at_utc')
            ->orderByDesc('delay_minutes')
            ->limit(200)
            ->get();

        return FlightResource::collection($flights);
    }

    private function board(Request $request, string $iata, string $boardType): AnonymousResourceCollection
    {
        $request->validate(['date' => 'nullable|date']);

        $airport = Airport::where('iata', strtoupper($iata))->firstOrFail();

        $date = $request->input('date', now()->setTimezone($airport->timezone ?? 'UTC')->toDateString());

        $flights = FlightCurrent::where('airport_id', $airport->id)
            ->where('board_type', $boardType)
            ->whereDate('service_date_local', $date)
            ->where('is_active', true)
            ->orderBy(
                $boardType === 'departures' ? 'scheduled_departure_at_utc' : 'scheduled_arrival_at_utc'
            )
            ->get();

        return FlightResource::collection($flights);
    }
}
