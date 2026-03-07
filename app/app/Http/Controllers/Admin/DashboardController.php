<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\Airport;
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
