<?php

namespace Tests\Feature\Scraping;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Jobs\OpenParserFailureJob;
use App\Domain\Scraping\Jobs\NormalizeScrapePayloadJob;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Domain\Scraping\Services\ScrapeRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for Step 8: soft-failure detection in ScrapeAirportSourceJob.
 *
 * Uses Queue::fake() to assert dispatches without executing them.
 * Severity escalation and ParserFailure creation are tested in OpenParserFailureJobTest.
 */
class SoftFailureDetectionTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private ParserVersion $parserVersion;
    private ScrapeJob $scrapeJob;

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

        $this->scrapeJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $this->parserVersion->id,
            'status'            => 'pending',
        ]);
    }

    public function test_zero_rows_dispatches_open_parser_failure_job(): void
    {
        Queue::fake();
        $this->bindFakeClient(rows: [], qualityScore: 0.0);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertPushedOn('repairs', OpenParserFailureJob::class, function ($job) {
            return $job->errorCode === 'zero_rows'
                && $job->scrapeJobId === $this->scrapeJob->id
                && $job->airportSourceId === $this->source->id;
        });

        // Normalization is still dispatched for the empty row set
        Queue::assertPushedOn('normalize', NormalizeScrapePayloadJob::class);
    }

    public function test_low_quality_score_below_threshold_dispatches_open_parser_failure_job(): void
    {
        Queue::fake();
        // 3 rows but quality_score = 0.3, below default threshold of 0.5
        $this->bindFakeClient(rows: $this->makeRows(3), qualityScore: 0.3);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertPushedOn('repairs', OpenParserFailureJob::class, function ($job) {
            return $job->errorCode === 'low_quality_score';
        });
    }

    public function test_quality_score_at_threshold_does_not_dispatch_failure(): void
    {
        Queue::fake();
        // Exactly at default threshold (0.5) — should NOT trigger
        $this->bindFakeClient(rows: $this->makeRows(5), qualityScore: 0.5);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertNotPushed(OpenParserFailureJob::class);
    }

    public function test_quality_score_above_threshold_does_not_dispatch_failure(): void
    {
        Queue::fake();
        $this->bindFakeClient(rows: $this->makeRows(10), qualityScore: 0.85);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertNotPushed(OpenParserFailureJob::class);
    }

    public function test_zero_rows_only_dispatches_one_failure_not_two(): void
    {
        // When rows = 0, the low_quality check is guarded with `$rowCount > 0`,
        // so we get exactly one failure record (zero_rows), not two.
        Queue::fake();
        $this->bindFakeClient(rows: [], qualityScore: 0.0);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertPushed(OpenParserFailureJob::class, 1);
    }

    public function test_scrape_job_is_marked_success_even_when_soft_failure_detected(): void
    {
        Queue::fake();
        $this->bindFakeClient(rows: [], qualityScore: 0.0);

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        $this->scrapeJob->refresh();
        $this->assertEquals('success', $this->scrapeJob->status);
        $this->assertEquals(0, $this->scrapeJob->row_count);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function bindFakeClient(array $rows, float $qualityScore): void
    {
        $this->app->bind(ScrapeRuntimeClient::class, function () use ($rows, $qualityScore) {
            return new class ($rows, $qualityScore) extends ScrapeRuntimeClient {
                public function __construct(private readonly array $rows, private readonly float $score)
                {
                    // skip parent constructor — no config needed
                }

                public function scrape($source, $parserVersion, int $scrapeJobId): array
                {
                    return [
                        'rows'          => $this->rows,
                        'row_count'     => count($this->rows),
                        'quality_score' => $this->score,
                        'artifacts'     => [],
                    ];
                }
            };
        });
    }

    private function makeRows(int $count): array
    {
        return array_fill(0, $count, [
            'flight_number'              => 'TS001',
            'scheduled_departure_at_utc' => now()->toIso8601String(),
        ]);
    }
}
