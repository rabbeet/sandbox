<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Flights\Models\FlightChange;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeArtifact;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * GET /admin/airports — airports index with source health stats.
     */
    public function airports(): Response
    {
        $airports = Airport::with([
            'sources.activeParserVersion',
        ])
            ->withCount(['flights as active_flights_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('iata')
            ->get()
            ->map(fn ($airport) => $this->withHealthStats($airport));

        return Inertia::render('Admin/Airports/Index', [
            'airports' => $airports,
        ]);
    }

    /**
     * GET /admin/airports/{airport} — airport detail with job logs, flights, changes, artifacts, parsers, failures.
     */
    public function show(Airport $airport): Response
    {
        $airport->load(['sources.activeParserVersion', 'sources.parserVersions' => fn ($q) => $q->orderByDesc('version')]);

        $sourceIds = $airport->sources->pluck('id');

        // Recent scrape jobs across all sources
        $recentJobs = ScrapeJob::whereIn('airport_source_id', $sourceIds)
            ->with('airportSource:id,board_type,source_type')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($job) => [
                'id'               => $job->id,
                'board_type'       => $job->airportSource?->board_type,
                'source_type'      => $job->airportSource?->source_type,
                'status'           => $job->status,
                'started_at'       => $job->started_at,
                'completed_at'     => $job->completed_at,
                'duration_ms'      => $job->duration_ms,
                'row_count'        => $job->row_count,
                'quality_score'    => $job->quality_score,
                'error_code'       => $job->error_code,
                'error_message'    => $job->error_message,
            ]);

        // Current active flights
        $activeFlights = FlightCurrent::where('airport_id', $airport->id)
            ->where('is_active', true)
            ->orderBy('scheduled_departure_at_utc')
            ->limit(50)
            ->get()
            ->map(fn ($f) => [
                'id'                          => $f->id,
                'board_type'                  => $f->board_type,
                'flight_number'               => $f->flight_number,
                'airline_name'                => $f->airline_name,
                'origin_iata'                 => $f->origin_iata,
                'destination_iata'            => $f->destination_iata,
                'service_date_local'          => $f->service_date_local?->toDateString(),
                'scheduled_departure_at_utc'  => $f->scheduled_departure_at_utc,
                'estimated_departure_at_utc'  => $f->estimated_departure_at_utc,
                'actual_departure_at_utc'     => $f->actual_departure_at_utc,
                'departure_gate'              => $f->departure_gate,
                'arrival_gate'                => $f->arrival_gate,
                'baggage_belt'                => $f->baggage_belt,
                'status_normalized'           => $f->status_normalized,
                'delay_minutes'               => $f->delay_minutes,
                'last_seen_at'                => $f->last_seen_at,
            ]);

        // Recent flight changes
        $flightCurrentIds = FlightCurrent::where('airport_id', $airport->id)
            ->pluck('id');

        $recentChanges = FlightChange::whereIn('flight_current_id', $flightCurrentIds)
            ->with('flightCurrent:id,flight_number,board_type')
            ->orderByDesc('changed_at')
            ->limit(30)
            ->get()
            ->map(fn ($c) => [
                'id'            => $c->id,
                'flight_number' => $c->flightCurrent?->flight_number,
                'board_type'    => $c->flightCurrent?->board_type,
                'field_name'    => $c->field_name,
                'old_value'     => $c->old_value,
                'new_value'     => $c->new_value,
                'changed_at'    => $c->changed_at,
            ]);

        // Latest artifacts
        $jobIds = ScrapeJob::whereIn('airport_source_id', $sourceIds)->pluck('id');
        $latestArtifacts = ScrapeArtifact::whereIn('scrape_job_id', $jobIds)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'id'           => $a->id,
                'artifact_type'=> $a->artifact_type,
                'storage_path' => $a->storage_path,
                'size_bytes'   => $a->size_bytes,
                'expires_at'   => $a->expires_at,
                'created_at'   => $a->created_at,
            ]);

        // Parser versions per source
        $sourcesWithParsers = $airport->sources->map(fn ($source) => [
            'id'                     => $source->id,
            'board_type'             => $source->board_type,
            'source_type'            => $source->source_type,
            'url'                    => $source->url,
            'is_active'              => $source->is_active,
            'scrape_interval_minutes'=> $source->scrape_interval_minutes,
            'active_parser_version'  => $source->activeParserVersion?->version,
            'parser_versions'        => $source->parserVersions->map(fn ($pv) => [
                'id'           => $pv->id,
                'version'      => $pv->version,
                'is_active'    => $pv->is_active,
                'activated_at' => $pv->activated_at,
                'created_at'   => $pv->created_at,
            ]),
        ]);

        // Failures
        $failures = ParserFailure::whereIn('airport_source_id', $sourceIds)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($f) => [
                'id'            => $f->id,
                'failure_type'  => $f->failure_type,
                'severity'      => $f->severity,
                'error_code'    => $f->error_code,
                'error_message' => $f->error_message,
                'status'        => $f->status,
                'resolved_at'   => $f->resolved_at,
                'created_at'    => $f->created_at,
            ]);

        return Inertia::render('Admin/Airports/Show', [
            'airport'        => [
                'id'        => $airport->id,
                'iata'      => $airport->iata,
                'icao'      => $airport->icao,
                'name'      => $airport->name,
                'city'      => $airport->city,
                'country'   => $airport->country,
                'timezone'  => $airport->timezone,
                'is_active' => $airport->is_active,
            ],
            'sources'        => $sourcesWithParsers,
            'recent_jobs'    => $recentJobs,
            'active_flights' => $activeFlights,
            'recent_changes' => $recentChanges,
            'artifacts'      => $latestArtifacts,
            'failures'       => $failures,
        ]);
    }

    private function withHealthStats(Airport $airport): array
    {
        $cutoff = now()->subDay();

        $sourcesData = $airport->sources->map(function ($source) use ($cutoff) {
            // Last success / failure timestamps from scrape_jobs
            $lastSuccess = ScrapeJob::where('airport_source_id', $source->id)
                ->where('status', 'success')
                ->latest('completed_at')
                ->value('completed_at');

            $lastFailure = ScrapeJob::where('airport_source_id', $source->id)
                ->whereIn('status', ['failed', 'timeout'])
                ->latest('completed_at')
                ->value('completed_at');

            // 24h success rate
            $jobs24h = ScrapeJob::where('airport_source_id', $source->id)
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('status, COUNT(*) as cnt')
                ->groupBy('status')
                ->pluck('cnt', 'status');

            $total24h = $jobs24h->sum();
            $success24h = $jobs24h->get('success', 0);
            $successRate24h = $total24h > 0
                ? round($success24h / $total24h * 100, 1)
                : null;

            // Latest run stats
            $latestJob = ScrapeJob::where('airport_source_id', $source->id)
                ->latest('created_at')
                ->first(['row_count', 'status', 'completed_at', 'error_code']);

            return [
                'id'               => $source->id,
                'board_type'       => $source->board_type,
                'source_type'      => $source->source_type,
                'is_active'        => $source->is_active,
                'last_success_at'  => $lastSuccess,
                'last_failure_at'  => $lastFailure,
                'success_rate_24h' => $successRate24h,
                'latest_row_count' => $latestJob?->row_count,
                'latest_job_status'=> $latestJob?->status,
                'parser_version'   => $source->activeParserVersion
                    ? $source->activeParserVersion->version
                    : null,
            ];
        });

        return [
            'id'                  => $airport->id,
            'iata'                => $airport->iata,
            'name'                => $airport->name,
            'city'                => $airport->city,
            'country'             => $airport->country,
            'is_active'           => $airport->is_active,
            'active_flights_count'=> $airport->active_flights_count,
            'sources'             => $sourcesData,
        ];
    }
}
