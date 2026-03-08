<?php

namespace Tests\Feature\Integration;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 16 — Full pipeline integration test.
 *
 * Exercises the complete job chain end-to-end:
 *   ScrapeAirportSourceJob → NormalizeScrapePayloadJob → UpdateFlightCurrentStateJob
 *
 * The queue driver is set to 'sync' so every dispatch() call inside a job executes
 * inline within the same test. Http::fake() intercepts the scraper runtime HTTP call.
 * No real network, no real queue worker — but every line of production pipeline code
 * is executed in the correct order under realistic conditions.
 *
 * WHAT THESE TESTS PROVE
 * ----------------------
 * - The job chain is wired correctly (dispatch arguments match constructor signatures).
 * - Raw HTTP rows reach FlightCurrent with correct field mapping and normalization.
 * - First observation creates FlightInstance + FlightCurrent but no FlightChange.
 * - Subsequent observations update FlightCurrent and produce FlightChange records
 *   only for fields that actually changed.
 * - Canonical key stability: same flight across two scrapes → one FlightCurrent,
 *   one FlightInstance, two FlightSnapshots.
 * - Soft failure (zero rows): ScrapeJob still marked success; ParserFailure opened.
 * - Arrivals delay calculation uses arrival times, not departure times.
 */
class FullPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Airport $airport;
    private AirportSource $source;
    private ParserVersion $parserVersion;

    protected function setUp(): void
    {
        parent::setUp();

        // All jobs dispatched inside the pipeline run synchronously.
        // This makes the entire chain (Scrape → Normalize → StateUpdate → optional Repair)
        // execute inline when ScrapeAirportSourceJob::dispatchSync() is called.
        config(['queue.default' => 'sync']);

        // Prevent accidental real HTTP calls (any un-faked URL will throw).
        Http::preventStrayRequests();

        $this->airport = Airport::create([
            'iata'      => 'JFK',
            'name'      => 'John F. Kennedy',
            'city'      => 'New York',
            'country'   => 'US',
            'timezone'  => 'America/New_York',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $this->airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/flights',
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

    // -------------------------------------------------------------------------
    // Test 1: First scrape — happy path
    // -------------------------------------------------------------------------

    public function test_first_scrape_creates_instance_current_and_snapshot(): void
    {
        Http::fake([
            '*/scrape' => Http::response($this->scrapeResponse([
                $this->flightRow('AA100', 'On Time'),
                $this->flightRow('UA200', 'Scheduled'),
            ], row_count: 2)),
        ]);

        $scrapeJob = $this->pendingScrapeJob();
        ScrapeAirportSourceJob::dispatchSync($scrapeJob->id, $this->source->id);

        // ScrapeJob completed successfully
        $scrapeJob->refresh();
        $this->assertEquals('success', $scrapeJob->status);
        $this->assertEquals(2, $scrapeJob->row_count);
        $this->assertNotNull($scrapeJob->completed_at);

        // Two snapshots created, one per flight row
        $this->assertDatabaseCount('flight_snapshots', 2);

        // Two stable identity records created
        $this->assertDatabaseCount('flight_instances', 2);
        $this->assertDatabaseHas('flight_instances', [
            'airport_id'    => $this->airport->id,
            'board_type'    => 'departures',
            'flight_number' => 'AA100',
        ]);

        // Two mutable projections created
        $this->assertDatabaseCount('flights_current', 2);

        $fc = FlightCurrent::where('flight_number', 'AA100')->first();
        $this->assertNotNull($fc);
        $this->assertEquals('On Time', $fc->status_raw);
        $this->assertEquals('on_time', $fc->status_normalized);
        $this->assertNotNull($fc->flight_instance_id);
        $this->assertTrue($fc->is_active);

        // No changes on first observation — there is no prior state to diff against
        $this->assertDatabaseCount('flight_changes', 0);
    }

    // -------------------------------------------------------------------------
    // Test 2: Second scrape — field updates produce FlightChange records
    // -------------------------------------------------------------------------

    public function test_second_scrape_updates_state_and_records_changes(): void
    {
        // Serve two different responses in sequence via a counter closure.
        // Using Http::fake() twice in the same test appends stubs rather than replacing
        // them, so a single fake with sequential behaviour is safer.
        $call = 0;
        Http::fake([
            '*/scrape' => function () use (&$call) {
                $call++;
                if ($call === 1) {
                    return Http::response($this->scrapeResponse([$this->flightRow('AA100', 'On Time')]));
                }
                return Http::response($this->scrapeResponse([
                    array_merge($this->flightRow('AA100', 'Delayed'), ['departure_gate' => 'B22']),
                ]));
            },
        ]);

        // First scrape: AA100 On Time, no gate
        ScrapeAirportSourceJob::dispatchSync($this->pendingScrapeJob()->id, $this->source->id);

        // Second scrape: AA100 Delayed, gate B22
        ScrapeAirportSourceJob::dispatchSync($this->pendingScrapeJob()->id, $this->source->id);

        // Still only one identity record and one current projection (no duplication)
        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);

        $fc = FlightCurrent::where('flight_number', 'AA100')->first();
        $this->assertEquals('Delayed', $fc->status_raw);
        $this->assertEquals('delayed', $fc->status_normalized);
        $this->assertEquals('B22', $fc->departure_gate);

        // FlightChange records created for every TRACKED_FIELD that changed
        $changes = $fc->changes()->orderBy('id')->get();

        // status_raw changed: 'On Time' → 'Delayed'
        $statusChange = $changes->firstWhere('field_name', 'status_raw');
        $this->assertNotNull($statusChange, 'Expected FlightChange for status_raw');
        $this->assertEquals('On Time', $statusChange->old_value);
        $this->assertEquals('Delayed', $statusChange->new_value);

        // status_normalized changed: 'on_time' → 'delayed'
        $this->assertNotNull($changes->firstWhere('field_name', 'status_normalized'));

        // departure_gate added: null → 'B22'
        $gateChange = $changes->firstWhere('field_name', 'departure_gate');
        $this->assertNotNull($gateChange, 'Expected FlightChange for departure_gate');
        $this->assertEquals('B22', $gateChange->new_value);

        // Two scrapes → two snapshots, but one persistent identity
        $this->assertDatabaseCount('flight_snapshots', 2);

        // Snapshots are back-filled with the flight_instance_id
        $this->assertDatabaseMissing('flight_snapshots', ['flight_instance_id' => null]);
    }

    // -------------------------------------------------------------------------
    // Test 3: Canonical key stability
    // -------------------------------------------------------------------------

    public function test_unchanged_fields_do_not_produce_flight_change_records(): void
    {
        $row = $this->flightRow('BA300', 'On Time');

        Http::fake(['*/scrape' => Http::response($this->scrapeResponse([$row]))]);
        ScrapeAirportSourceJob::dispatchSync($this->pendingScrapeJob()->id, $this->source->id);

        // Second scrape — identical row, nothing changed
        Http::fake(['*/scrape' => Http::response($this->scrapeResponse([$row]))]);
        ScrapeAirportSourceJob::dispatchSync($this->pendingScrapeJob()->id, $this->source->id);

        $this->assertDatabaseCount('flight_changes', 0);
        $this->assertDatabaseCount('flights_current', 1);
        $this->assertDatabaseCount('flight_instances', 1);

        // last_seen_at is updated even when nothing changed
        $fc = FlightCurrent::where('flight_number', 'BA300')->first();
        $this->assertNotNull($fc->last_seen_at);
    }

    // -------------------------------------------------------------------------
    // Test 4: Soft failure — zero rows
    // -------------------------------------------------------------------------

    public function test_zero_rows_marks_scrape_success_and_opens_parser_failure(): void
    {
        Http::fake([
            '*/scrape' => Http::response($this->scrapeResponse([], row_count: 0, quality: 0.0)),
        ]);

        $scrapeJob = $this->pendingScrapeJob();
        ScrapeAirportSourceJob::dispatchSync($scrapeJob->id, $this->source->id);

        // ScrapeJob is still marked success — zero rows is a soft failure, not a hard one
        $scrapeJob->refresh();
        $this->assertEquals('success', $scrapeJob->status);
        $this->assertEquals(0, $scrapeJob->row_count);

        // No flight data created
        $this->assertDatabaseCount('flight_snapshots', 0);
        $this->assertDatabaseCount('flight_instances', 0);
        $this->assertDatabaseCount('flights_current', 0);

        // A ParserFailure is opened automatically
        $this->assertDatabaseCount('parser_failures', 1);
        $this->assertDatabaseHas('parser_failures', [
            'airport_source_id' => $this->source->id,
            'error_code'        => 'zero_rows',
            'failure_type'      => 'soft',
            'status'            => 'open',
            'severity'          => 'low',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 5: Arrivals board — delay calculation uses arrival times
    // -------------------------------------------------------------------------

    public function test_arrivals_delay_uses_arrival_times_not_departure_times(): void
    {
        $arrivalsSource = AirportSource::create([
            'airport_id'              => $this->airport->id,
            'board_type'              => 'arrivals',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/arrivals',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        $arrivalsParser = ParserVersion::create([
            'airport_source_id' => $arrivalsSource->id,
            'version'           => 1,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => true,
            'activated_at'      => now(),
        ]);

        $arrivalsSource->update(['active_parser_version_id' => $arrivalsParser->id]);

        Http::fake([
            '*/scrape' => Http::response($this->scrapeResponse([
                [
                    'flight_number'              => 'LH401',
                    'status_raw'                 => 'Delayed',
                    'service_date_local'         => '2026-03-08',
                    'scheduled_arrival_at_utc'   => '2026-03-08T10:00:00Z',
                    'estimated_arrival_at_utc'   => '2026-03-08T10:20:00Z',
                    // departure times deliberately absent — arrivals board
                ],
            ])),
        ]);

        $scrapeJob = ScrapeJob::create([
            'airport_source_id' => $arrivalsSource->id,
            'status'            => 'pending',
        ]);

        ScrapeAirportSourceJob::dispatchSync($scrapeJob->id, $arrivalsSource->id);

        $fc = FlightCurrent::where('flight_number', 'LH401')->first();
        $this->assertNotNull($fc);
        $this->assertEquals(20, $fc->delay_minutes); // 10:20 − 10:00 = 20 min
    }

    // -------------------------------------------------------------------------
    // Test 6: Scrape failure — runtime error marks job failed, no partial state
    // -------------------------------------------------------------------------

    public function test_scraper_runtime_error_marks_job_failed_and_creates_no_data(): void
    {
        Http::fake([
            '*/scrape' => Http::response('Internal Server Error', 500),
        ]);

        $scrapeJob = $this->pendingScrapeJob();
        ScrapeAirportSourceJob::dispatchSync($scrapeJob->id, $this->source->id);

        $scrapeJob->refresh();
        $this->assertEquals('failed', $scrapeJob->status);
        $this->assertNotNull($scrapeJob->error_code);

        $this->assertDatabaseCount('flight_snapshots', 0);
        $this->assertDatabaseCount('flights_current', 0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function pendingScrapeJob(): ScrapeJob
    {
        return ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'pending',
            'triggered_by'      => 'scheduler',
        ]);
    }

    /**
     * Build a minimal departures flight row. Only fields the normaliser needs
     * for a complete, unambiguous record. Additional fields can be merged in.
     */
    private function flightRow(string $flightNumber, string $status): array
    {
        return [
            'flight_number'              => $flightNumber,
            'status_raw'                 => $status,
            'service_date_local'         => '2026-03-08',
            'scheduled_departure_at_utc' => '2026-03-08T20:00:00Z',
        ];
    }

    /**
     * Wrap rows in the scraper runtime response envelope.
     */
    private function scrapeResponse(
        array $rows,
        int $row_count = -1,
        float $quality = 0.95,
    ): array {
        return [
            'rows'          => $rows,
            'row_count'     => $row_count === -1 ? count($rows) : $row_count,
            'quality_score' => $quality,
            'artifacts'     => [],
        ];
    }
}
