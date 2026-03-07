<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Airports\StoreAirportSourceRequest;
use App\Http\Requests\Airports\UpdateAirportSourceRequest;
use App\Http\Resources\Airports\AirportSourceResource;
use Illuminate\Http\JsonResponse;

class AirportSourceController extends Controller
{
    public function index(Airport $airport): JsonResponse
    {
        $this->authorize('airport-sources.view');

        $sources = $airport->sources()->with('activeParserVersion')->get();

        return response()->json(AirportSourceResource::collection($sources));
    }

    public function store(StoreAirportSourceRequest $request, Airport $airport): AirportSourceResource
    {
        $source = $airport->sources()->create($request->validated());

        return new AirportSourceResource($source);
    }

    public function show(Airport $airport, AirportSource $source): AirportSourceResource
    {
        $this->authorize('airport-sources.view');

        $this->ensureSourceBelongsToAirport($airport, $source);

        return new AirportSourceResource($source->load('activeParserVersion', 'parserVersions'));
    }

    public function update(UpdateAirportSourceRequest $request, Airport $airport, AirportSource $source): AirportSourceResource
    {
        $this->ensureSourceBelongsToAirport($airport, $source);

        $source->update($request->validated());

        return new AirportSourceResource($source->fresh('activeParserVersion'));
    }

    public function destroy(Airport $airport, AirportSource $source): JsonResponse
    {
        $this->authorize('airport-sources.delete');

        $this->ensureSourceBelongsToAirport($airport, $source);

        $source->delete();

        return response()->json(null, 204);
    }

    private function ensureSourceBelongsToAirport(Airport $airport, AirportSource $source): void
    {
        abort_if($source->airport_id !== $airport->id, 404);
    }
}
