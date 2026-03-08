<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
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

    /**
     * POST /api/sources/{source}/scrape
     *
     * Manually trigger an immediate scrape for the given source, bypassing the
     * scheduler. Creates a pending ScrapeJob and dispatches ScrapeAirportSourceJob
     * to the appropriate queue.
     *
     * OPERATIONAL NOTES
     * -----------------
     * - The source must have an active parser version. Without one, the scrape job
     *   would immediately fail with 'invalid_parser_definition'. We surface this as
     *   a 422 here to give faster feedback.
     * - If a scrape for the same source is already pending or running, this endpoint
     *   returns 409 Conflict with the in-progress job's id. This prevents multiple
     *   concurrent browser sessions being spun up for the same source. The check is
     *   advisory, not a distributed lock — a race between two near-simultaneous API
     *   calls could still slip two jobs through, but the ScrapeAirportSourceJob
     *   idempotency guard (status !== 'pending' check) prevents the second one from
     *   executing.
     * - The triggered scrape is identical to a scheduled scrape — same queue, retry
     *   policy, artifact handling, and soft-failure detection. It is NOT priority-
     *   elevated beyond the high-priority queue for tier-1 airports.
     *
     * RESPONSE
     * --------
     * 202 Accepted: scrape queued. Poll scrape_jobs for results.
     * 409 Conflict: a pending/running scrape already exists for this source.
     */
    public function scrape(AirportSource $source): JsonResponse
    {
        $this->authorize('scrape-jobs.trigger');

        if (! $source->is_active) {
            return response()->json(['message' => 'Source is not active.'], 422);
        }

        if (! $source->activeParserVersion) {
            return response()->json(['message' => 'Source has no active parser version.'], 422);
        }

        // Guard against concurrent browser sessions: return the in-progress job
        // rather than queuing a second one. Not a hard lock — see operational note above.
        $inProgress = ScrapeJob::where('airport_source_id', $source->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest('created_at')
            ->first();

        if ($inProgress) {
            return response()->json([
                'scrape_job_id' => $inProgress->id,
                'status'        => $inProgress->status,
                'message'       => 'A scrape for this source is already pending or running.',
            ], 409);
        }

        $scrapeJob = ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'pending',
            'triggered_by'      => 'manual',
        ]);

        // Use the same queue selection logic as the scheduler (high-priority for
        // known large airports). Manual triggers are not elevated beyond that.
        $iata  = $source->airport?->iata ?? '';
        $highPriority = in_array($iata, config('scraper.high_priority_airports', []), true);
        $queue = $highPriority ? 'scrape-high' : 'scrape-default';

        ScrapeAirportSourceJob::dispatch($scrapeJob->id, $source->id)
            ->onQueue($queue);

        return response()->json([
            'scrape_job_id' => $scrapeJob->id,
            'status'        => 'pending',
            'message'       => 'Scrape queued. Poll scrape_jobs for results.',
        ], 202);
    }

    private function ensureSourceBelongsToAirport(Airport $airport, AirportSource $source): void
    {
        abort_if($source->airport_id !== $airport->id, 404);
    }
}
