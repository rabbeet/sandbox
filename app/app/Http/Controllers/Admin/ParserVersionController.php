<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Flights\Jobs\UpdateFlightCurrentStateJob;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParserVersionController extends Controller
{
    /**
     * GET /api/sources/{source}/parser-versions
     *
     * List all parser versions for the source, newest first.
     */
    public function index(AirportSource $source): JsonResponse
    {
        $this->authorize('parser-versions.view');

        $versions = $source->parserVersions()
            ->orderByDesc('version')
            ->get()
            ->map(fn ($pv) => $this->formatVersion($pv));

        return response()->json(['data' => $versions]);
    }

    /**
     * POST /api/sources/{source}/parser-versions
     *
     * Create a new parser version for the source. Does NOT activate it —
     * activation is a separate explicit step to avoid accidental rollout.
     *
     * The 'version' field is auto-incremented: (max existing version for this
     * source) + 1. Callers should not supply it.
     */
    public function store(Request $request, AirportSource $source): JsonResponse
    {
        $this->authorize('parser-versions.create');

        $validated = $request->validate([
            'definition' => ['required', 'array'],
        ]);

        $nextVersion = ($source->parserVersions()->max('version') ?? 0) + 1;

        $parserVersion = $source->parserVersions()->create([
            'version'    => $nextVersion,
            'definition' => $validated['definition'],
            'is_active'  => false,
            'created_by' => $request->user()?->id,
        ]);

        Log::info('ParserVersionController: new version created', [
            'source_id'         => $source->id,
            'parser_version_id' => $parserVersion->id,
            'version'           => $parserVersion->version,
        ]);

        return response()->json(['data' => $this->formatVersion($parserVersion)], 201);
    }

    /**
     * POST /api/sources/{source}/parser-versions/{parserVersion}/activate
     *
     * Activate a parser version, deactivating all others for this source.
     *
     * TRANSACTIONAL: both the deactivation of existing active versions and the
     * activation of the new version are committed atomically. If the transaction
     * fails, the source retains its previous active version.
     *
     * OPERATIONAL NOTE: Activation takes effect on the next scheduled scrape.
     * It does NOT retroactively re-run any scrapes. If you want to immediately
     * verify the new parser, use POST /api/sources/{source}/scrape after activation.
     */
    public function activate(AirportSource $source, ParserVersion $parserVersion): JsonResponse
    {
        $this->authorize('parser-versions.activate');

        abort_if($parserVersion->airport_source_id !== $source->id, 404);

        DB::transaction(function () use ($source, $parserVersion) {
            // Deactivate all existing active versions for this source
            $source->parserVersions()
                ->where('is_active', true)
                ->update([
                    'is_active'      => false,
                    'deactivated_at' => now(),
                ]);

            // Activate the requested version
            $parserVersion->update([
                'is_active'    => true,
                'activated_at' => now(),
            ]);

            // Wire the source's FK to the newly active version
            $source->update(['active_parser_version_id' => $parserVersion->id]);
        });

        Log::info('ParserVersionController: version activated', [
            'source_id'         => $source->id,
            'parser_version_id' => $parserVersion->id,
            'version'           => $parserVersion->version,
        ]);

        return response()->json(['data' => $this->formatVersion($parserVersion->fresh())]);
    }

    /**
     * POST /api/sources/{source}/parser-versions/{parserVersion}/replay
     *
     * Re-apply the state-update pipeline to all existing FlightSnapshots that were
     * produced by scrape jobs under the given parser version. Does NOT re-scrape,
     * does NOT create new snapshots.
     *
     * WHAT REPLAY DOES
     * ----------------
     * For each existing FlightSnapshot (already stored, already assigned to a
     * FlightInstance), dispatches UpdateFlightCurrentStateJob with the snapshot's
     * stored normalized_payload. This re-applies field changes, delay calculations,
     * and FlightCurrent updates using the current code — without touching the
     * identity layer or snapshot history.
     *
     * WHAT REPLAY DOES NOT DO
     * -----------------------
     * - It does NOT re-normalize raw_payload → normalized_payload. If a normalization
     *   bug was fixed (e.g., a field mapping changed), the old normalized_payload
     *   stored in existing snapshots still contains the incorrect values. To fix
     *   those, you need a separate migration or command that re-runs
     *   NormalizeScrapePayloadJob against the stored raw_payload.
     * - It does NOT create new FlightSnapshot rows. Existing snapshots are the
     *   record of what the scraper returned; they are append-only by design.
     *
     * IDEMPOTENCY
     * -----------
     * UpdateFlightCurrentStateJob is idempotent: it resolves-or-creates
     * FlightInstance (using the existing snapshot's flight_instance_id, which was
     * already set when the snapshot was first processed) and updates FlightCurrent
     * in-place. Multiple replays produce the same FlightCurrent state; no records
     * are duplicated.
     *
     * OPERATIONAL LIMITS
     * ------------------
     * - Only snapshots with flight_instance_id set are replayed (i.e., those that
     *   completed the original pipeline). Orphaned snapshots are skipped — they
     *   indicate a prior processing failure and should be investigated separately.
     * - Replay goes to the 'state-update' queue alongside normal live traffic.
     *   For large replays on busy airports, run during low-traffic windows.
     * - The `limit` parameter caps snapshots per request (default 100, max 500).
     *   For large historical replays, page through with multiple calls.
     */
    public function replay(Request $request, AirportSource $source, ParserVersion $parserVersion): JsonResponse
    {
        $this->authorize('parser-versions.replay');

        abort_if($parserVersion->airport_source_id !== $source->id, 404);

        $limit  = min((int) $request->integer('limit', 100), 500);
        $airport = $source->load('airport')->airport;

        if (! $airport) {
            return response()->json(['message' => 'Source has no associated airport.'], 422);
        }

        // Collect scrape job IDs for this parser version
        $scrapeJobIds = ScrapeJob::where('airport_source_id', $source->id)
            ->where('parser_version_id', $parserVersion->id)
            ->where('status', 'success')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->pluck('id');

        if ($scrapeJobIds->isEmpty()) {
            return response()->json(['dispatched' => 0, 'message' => 'No successful scrape jobs found for this parser version.']);
        }

        // Re-dispatch state updates for existing snapshots — no new snapshots created.
        // Only snapshots with flight_instance_id set (fully processed) are replayed.
        $snapshots = FlightSnapshot::whereIn('scrape_job_id', $scrapeJobIds)
            ->whereNotNull('flight_instance_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'scrape_job_id', 'canonical_key', 'normalized_payload']);

        $dispatched = 0;
        foreach ($snapshots as $snapshot) {
            UpdateFlightCurrentStateJob::dispatch(
                $snapshot->scrape_job_id,
                $airport->id,
                $snapshot->id,
                $snapshot->canonical_key,
                $snapshot->normalized_payload,
            )->onQueue('state-update');
            $dispatched++;
        }

        Log::info('ParserVersionController: replay dispatched', [
            'source_id'         => $source->id,
            'parser_version_id' => $parserVersion->id,
            'version'           => $parserVersion->version,
            'dispatched'        => $dispatched,
        ]);

        return response()->json([
            'dispatched' => $dispatched,
            'message'    => "{$dispatched} normalization job(s) queued for replay.",
        ]);
    }

    private function formatVersion(ParserVersion $pv): array
    {
        return [
            'id'           => $pv->id,
            'version'      => $pv->version,
            'definition'   => $pv->definition,
            'is_active'    => $pv->is_active,
            'activated_at' => $pv->activated_at,
            'created_at'   => $pv->created_at,
        ];
    }
}
