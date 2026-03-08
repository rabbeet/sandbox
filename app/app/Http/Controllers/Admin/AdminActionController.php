<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminActionController extends Controller
{
    /**
     * POST /admin/sources/{source}/scrape
     *
     * Manually trigger an immediate scrape from the web UI.
     * Redirects back with a flash message.
     */
    public function triggerScrape(AirportSource $source): RedirectResponse
    {
        if (! $source->is_active) {
            return back()->with('error', 'Source is not active.');
        }

        if (! $source->activeParserVersion) {
            return back()->with('error', 'Source has no active parser version.');
        }

        $inProgress = ScrapeJob::where('airport_source_id', $source->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($inProgress) {
            return back()->with('error', 'A scrape for this source is already pending or running.');
        }

        $scrapeJob = ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'pending',
            'triggered_by'      => 'manual',
        ]);

        $iata         = $source->airport?->iata ?? '';
        $highPriority = in_array($iata, config('scraper.high_priority_airports', []), true);
        $queue        = $highPriority ? 'scrape-high' : 'scrape-default';

        ScrapeAirportSourceJob::dispatch($scrapeJob->id, $source->id)->onQueue($queue);

        return back()->with('success', 'Scrape queued (job #' . $scrapeJob->id . ').');
    }

    /**
     * POST /admin/sources/{source}/parser-versions/{parserVersion}/activate
     *
     * Activate a parser version from the web UI.
     */
    public function activateParserVersion(AirportSource $source, ParserVersion $parserVersion): RedirectResponse
    {
        abort_if($parserVersion->airport_source_id !== $source->id, 404);

        DB::transaction(function () use ($source, $parserVersion) {
            $source->parserVersions()
                ->where('is_active', true)
                ->update(['is_active' => false, 'deactivated_at' => now()]);

            $parserVersion->update(['is_active' => true, 'activated_at' => now()]);
            $source->update(['active_parser_version_id' => $parserVersion->id]);
        });

        return back()->with('success', "Parser v{$parserVersion->version} activated.");
    }

    /**
     * PATCH /admin/failures/{failure}
     *
     * Update failure status from the web UI.
     */
    public function updateFailure(Request $request, ParserFailure $failure): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['investigating', 'repaired', 'ignored'])],
        ]);

        if (in_array($failure->status, ['repaired', 'ignored'], true)) {
            return back()->with('error', "Failure is already '{$failure->status}' (terminal).");
        }

        $updates = ['status' => $validated['status']];

        if (in_array($validated['status'], ['repaired', 'ignored'], true) && ! $failure->resolved_at) {
            $updates['resolved_at'] = now();
        }

        $failure->update($updates);

        return back()->with('success', "Failure marked as {$validated['status']}.");
    }
}
