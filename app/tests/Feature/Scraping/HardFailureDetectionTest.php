<?php

namespace Tests\Feature\Scraping;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Jobs\NormalizeScrapePayloadJob;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Domain\Scraping\Services\ScrapeRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Hard failure detection: confirms that scraper runtime errors (HTTP 4xx/5xx,
 * connection failures, unhandled exceptions) always produce a ParserFailure record
 * with failure_type='hard' and never write downstream flight data.
 *
 * Uses two complementary strategies:
 * - Queue::fake() tests confirm dispatch behaviour without executing downstream jobs.
 * - Sync-queue + Http::fake() tests confirm full end-to-end atomicity (no snapshots written).
 */
class HardFailureDetectionTest extends TestCase
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
            'triggered_by'      => 'scheduler',
        ]);
    }

    // -------------------------------------------------------------------------
    // HTTP 500 — server error
    // -------------------------------------------------------------------------

    public function test_http_500_opens_hard_parser_failure(): void
    {
        $this->bindClientThatThrows("scraper_runtime_http_500: Internal Server Error");

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        $this->assertDatabaseCount('parser_failures', 1);
        $this->assertDatabaseHas('parser_failures', [
            'airport_source_id' => $this->source->id,
            'scrape_job_id'     => $this->scrapeJob->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => 'scraper_runtime_http_500',
            'status'            => 'open',
        ]);

        // http_status stored in failure_details
        $failure = ParserFailure::first();
        $this->assertEquals(500, $failure->failure_details['http_status'] ?? null);
    }

    public function test_http_500_also_marks_scrape_job_failed(): void
    {
        $this->bindClientThatThrows("scraper_runtime_http_500: Internal Server Error");

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        $this->scrapeJob->refresh();
        $this->assertEquals('failed', $this->scrapeJob->status);
        $this->assertEquals('scraper_runtime_http_500', $this->scrapeJob->error_code);
    }

    // -------------------------------------------------------------------------
    // HTTP 422 — parser rejected the payload
    // -------------------------------------------------------------------------

    public function test_http_422_opens_hard_failure_with_correct_http_status(): void
    {
        $this->bindClientThatThrows("scraper_runtime_http_422: Unprocessable Entity");

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        $this->assertDatabaseCount('parser_failures', 1);

        $failure = ParserFailure::first();
        $this->assertEquals('hard', $failure->failure_type);
        $this->assertEquals('critical', $failure->severity);
        $this->assertEquals('scraper_runtime_http_422', $failure->error_code);
        $this->assertEquals(422, $failure->failure_details['http_status'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Connection failure — runtime unreachable
    // -------------------------------------------------------------------------

    public function test_connection_failure_opens_hard_parser_failure(): void
    {
        $this->bindClientThatThrows("scraper_runtime_unreachable: Connection refused");

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        $this->assertDatabaseCount('parser_failures', 1);

        $failure = ParserFailure::first();
        $this->assertEquals('hard', $failure->failure_type);
        $this->assertEquals('scraper_runtime_unreachable', $failure->error_code);
        // No http_status for connection failures
        $this->assertArrayNotHasKey('http_status', $failure->failure_details ?? []);
    }

    // -------------------------------------------------------------------------
    // Downstream dispatch guard
    // -------------------------------------------------------------------------

    public function test_hard_failure_does_not_dispatch_normalize_job(): void
    {
        Queue::fake();
        $this->bindClientThatThrows("scraper_runtime_http_500: Internal Server Error");

        (new ScrapeAirportSourceJob($this->scrapeJob->id, $this->source->id))
            ->handle(app(ScrapeRuntimeClient::class));

        Queue::assertNotPushed(NormalizeScrapePayloadJob::class);
    }

    // -------------------------------------------------------------------------
    // Full atomicity — no flight data written
    // -------------------------------------------------------------------------

    public function test_hard_failure_leaves_flight_snapshots_unchanged(): void
    {
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        Http::fake(['*/scrape' => Http::response('Internal Server Error', 500)]);

        ScrapeAirportSourceJob::dispatchSync($this->scrapeJob->id, $this->source->id);

        $this->assertDatabaseCount('flight_snapshots', 0);
        $this->assertDatabaseCount('flight_instances', 0);
        $this->assertDatabaseCount('flights_current', 0);

        // But the failure record must exist
        $this->assertDatabaseCount('parser_failures', 1);
    }

    // -------------------------------------------------------------------------
    // Soft failure path unchanged — must NOT produce a hard failure record
    // -------------------------------------------------------------------------

    public function test_soft_failure_zero_rows_does_not_open_hard_failure(): void
    {
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        Http::fake([
            '*/scrape' => Http::response([
                'rows'          => [],
                'row_count'     => 0,
                'quality_score' => 0.0,
                'artifacts'     => [],
            ]),
        ]);

        ScrapeAirportSourceJob::dispatchSync($this->scrapeJob->id, $this->source->id);

        // One soft failure, zero hard failures
        $this->assertDatabaseCount('parser_failures', 1);
        $this->assertDatabaseHas('parser_failures', [
            'failure_type' => 'soft',
        ]);
        $this->assertDatabaseMissing('parser_failures', [
            'failure_type' => 'hard',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function bindClientThatThrows(string $message): void
    {
        $this->app->bind(ScrapeRuntimeClient::class, function () use ($message) {
            return new class ($message) extends ScrapeRuntimeClient {
                public function __construct(private readonly string $msg)
                {
                    // skip parent constructor
                }

                public function scrape($source, $parserVersion, int $scrapeJobId): array
                {
                    throw new \RuntimeException($this->msg);
                }
            };
        });
    }
}
