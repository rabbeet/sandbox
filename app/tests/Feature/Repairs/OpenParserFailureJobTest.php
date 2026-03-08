<?php

namespace Tests\Feature\Repairs;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Jobs\OpenParserFailureJob;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for OpenParserFailureJob: record creation and severity escalation.
 */
class OpenParserFailureJobTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private ParserVersion $parserVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $airport = Airport::create([
            'iata'      => 'TST',
            'name'      => 'Test Airport',
            'city'      => 'Testville',
            'country'   => 'US',
            'timezone'  => 'UTC',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        $this->parserVersion = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 1,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => true,
            'activated_at'      => now(),
        ]);

        $this->source->update(['active_parser_version_id' => $this->parserVersion->id]);
    }

    public function test_first_failure_creates_open_record_with_low_severity(): void
    {
        $scrapeJob = $this->makeScrapeJob();

        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'zero_rows',
            errorMessage: 'Scrape returned zero rows.',
        ))->handle();

        $failure = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals('soft', $failure->failure_type);
        $this->assertEquals('low', $failure->severity);
        $this->assertEquals('open', $failure->status);
        $this->assertEquals('zero_rows', $failure->error_code);
    }

    public function test_third_consecutive_failure_escalates_to_medium(): void
    {
        // Two existing open failures (from prior scrapes)
        $this->makeFailureRecord('open');
        $this->makeFailureRecord('open');

        $scrapeJob = $this->makeScrapeJob();
        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'zero_rows',
            errorMessage: 'msg',
        ))->handle();

        $newest = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals('medium', $newest->severity); // consecutive = 3 → medium
    }

    public function test_fifth_consecutive_failure_escalates_to_high(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->makeFailureRecord('open');
        }

        $scrapeJob = $this->makeScrapeJob();
        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'zero_rows',
            errorMessage: 'msg',
        ))->handle();

        $newest = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals('high', $newest->severity); // consecutive = 5 → high
    }

    public function test_seventh_consecutive_failure_escalates_to_critical(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->makeFailureRecord('open');
        }

        $scrapeJob = $this->makeScrapeJob();
        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'zero_rows',
            errorMessage: 'msg',
        ))->handle();

        $newest = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals('critical', $newest->severity); // consecutive = 7 → critical
    }

    public function test_repaired_failure_resets_consecutive_count(): void
    {
        // A repaired failure first (oldest), then two new open failures
        $this->makeFailureRecord('repaired', created_at: now()->subHours(3));
        $this->makeFailureRecord('open', created_at: now()->subHours(2));
        $this->makeFailureRecord('open', created_at: now()->subHours(1));

        $scrapeJob = $this->makeScrapeJob();
        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'zero_rows',
            errorMessage: 'msg',
        ))->handle();

        // Streak: 2 open (before this one) → consecutive = 3 → medium.
        // The repaired row stops the count because it's older and the streak
        // query stops at first non-open/investigating row.
        $newest = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals('medium', $newest->severity);
    }

    public function test_failure_details_are_persisted(): void
    {
        $scrapeJob = $this->makeScrapeJob();

        (new OpenParserFailureJob(
            scrapeJobId: $scrapeJob->id,
            airportSourceId: $this->source->id,
            errorCode: 'low_quality_score',
            errorMessage: 'Score 0.3 below threshold 0.5.',
            failureDetails: ['quality_score' => 0.3, 'threshold' => 0.5, 'row_count' => 10],
        ))->handle();

        $failure = ParserFailure::where('scrape_job_id', $scrapeJob->id)->firstOrFail();
        $this->assertEquals(0.3, $failure->failure_details['quality_score']);
        $this->assertEquals(0.5, $failure->failure_details['threshold']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeScrapeJob(): ScrapeJob
    {
        return ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'status'            => 'success',
            'completed_at'      => now(),
        ]);
    }

    private function makeFailureRecord(string $status, ?\DateTimeInterface $created_at = null): ParserFailure
    {
        $record = ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'failure_type'      => 'soft',
            'severity'          => 'low',
            'error_code'        => 'zero_rows',
            'error_message'     => 'msg',
            'status'            => $status,
            'resolved_at'       => in_array($status, ['repaired', 'ignored']) ? now() : null,
        ]);

        // Backdate if requested
        if ($created_at !== null) {
            \Illuminate\Support\Facades\DB::table('parser_failures')
                ->where('id', $record->id)
                ->update(['created_at' => $created_at]);
        }

        return $record;
    }
}
