<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\Airport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Airports\StoreAirportRequest;
use App\Http\Requests\Airports\UpdateAirportRequest;
use App\Http\Resources\Airports\AirportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AirportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('airports.view');

        $airports = Airport::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->input('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('iata', 'ilike', "%{$s}%")
                    ->orWhere('name', 'ilike', "%{$s}%")
                    ->orWhere('city', 'ilike', "%{$s}%");
            }))
            ->with('sources')
            ->orderBy('iata')
            ->paginate($request->integer('per_page', 25));

        return AirportResource::collection($airports);
    }

    public function store(StoreAirportRequest $request): AirportResource
    {
        $airport = Airport::create($request->validated());

        return new AirportResource($airport);
    }

    public function show(Airport $airport): AirportResource
    {
        $this->authorize('airports.view');

        return new AirportResource($airport->load('sources'));
    }

    public function update(UpdateAirportRequest $request, Airport $airport): AirportResource
    {
        $airport->update($request->validated());

        return new AirportResource($airport->fresh('sources'));
    }

    public function destroy(Airport $airport): JsonResponse
    {
        $this->authorize('airports.delete');

        $airport->delete();

        return response()->json(null, 204);
    }
}
