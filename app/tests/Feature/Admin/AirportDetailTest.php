<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Flights\Models\FlightChange;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeArtifact;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportDetailTest extends TestCase
{
    use RefreshDatabase;

    private function inertiaGet(string $url)
    {
        return $this->withHeaders(['X-Inertia' => 'true'])->get($url);
    }

    private function makeAirport(string $iata = 'JFK'): Airport
    {
        return Airport::create([
            'iata'      => $iata,
            'name'      => "$iata Airport",
            'city'      => 'Test City',
            'country'   => 'US',
            'timezone'  => 'UTC',
            'is_active' => true,
        ]);
    }

    public function test_airport_detail_returns_inertia_response(): void
    {
        $airport = $this->makeAirport();

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $response->assertOk()->assertHeader('X-Inertia');
    }

    public function test_airport_detail_returns_airport_info(): void
    {
        $airport = $this->makeAirport('LHR');

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $data = $response->json('props.airport');
        $this->assertEquals('LHR', $data['iata']);
        $this->assertEquals('LHR Airport', $data['name']);
        $this->assertTrue($data['is_active']);
    }

    public function test_airport_detail_includes_sources_with_parser_versions(): void
    {
        $airport = $this->makeAirport('CDG');

        $source = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/cdg',
            'scrape_interval_minutes' => 10,
            'is_active'               => true,
        ]);

        $pv = ParserVersion::create([
            'airport_source_id' => $source->id,
            'version'           => 1,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => true,
            'activated_at'      => now(),
        ]);

        $source->update(['active_parser_version_id' => $pv->id]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $sources = $response->json('props.sources');
        $this->assertCount(1, $sources);
        $this->assertEquals('departures', $sources[0]['board_type']);
        $this->assertEquals(1, $sources[0]['active_parser_version']);
        $this->assertCount(1, $sources[0]['parser_versions']);
        $this->assertTrue($sources[0]['parser_versions'][0]['is_active']);
    }

    public function test_airport_detail_includes_recent_jobs(): void
    {
        $airport = $this->makeAirport('SVO');
        $source  = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'arrivals',
            'source_type'             => 'html_table',
            'url'                     => 'https://example.com/svo',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'success',
            'row_count'         => 88,
            'quality_score'     => 0.95,
            'started_at'        => now()->subMinutes(10),
            'completed_at'      => now()->subMinutes(9),
            'duration_ms'       => 3200,
        ]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $jobs = $response->json('props.recent_jobs');
        $this->assertCount(1, $jobs);
        $this->assertEquals('success', $jobs[0]['status']);
        $this->assertEquals(88, $jobs[0]['row_count']);
        $this->assertEquals(0.95, $jobs[0]['quality_score']);
    }

    public function test_airport_detail_includes_active_flights(): void
    {
        $airport = $this->makeAirport('AMS');

        FlightCurrent::create([
            'airport_id'         => $airport->id,
            'canonical_key'      => 'AMS:departures:2026-03-07:KL123:0800',
            'board_type'         => 'departures',
            'flight_number'      => 'KL123',
            'service_date_local' => '2026-03-07',
            'status_normalized'  => 'on_time',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'delay_minutes'      => 0,
        ]);

        // inactive flight — should not appear
        FlightCurrent::create([
            'airport_id'         => $airport->id,
            'canonical_key'      => 'AMS:departures:2026-03-07:KL999:0900',
            'board_type'         => 'departures',
            'flight_number'      => 'KL999',
            'service_date_local' => '2026-03-07',
            'status_normalized'  => 'departed',
            'is_active'          => false,
            'is_completed'       => true,
            'last_seen_at'       => now()->subHours(2),
        ]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $flights = $response->json('props.active_flights');
        $this->assertCount(1, $flights);
        $this->assertEquals('KL123', $flights[0]['flight_number']);
    }

    public function test_airport_detail_includes_recent_changes(): void
    {
        $airport = $this->makeAirport('FRA');

        $flight = FlightCurrent::create([
            'airport_id'         => $airport->id,
            'canonical_key'      => 'FRA:departures:2026-03-07:LH100:0600',
            'board_type'         => 'departures',
            'flight_number'      => 'LH100',
            'service_date_local' => '2026-03-07',
            'status_normalized'  => 'delayed',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
        ]);

        FlightChange::create([
            'flight_current_id' => $flight->id,
            'field_name'        => 'departure_gate',
            'old_value'         => 'A10',
            'new_value'         => 'B22',
            'changed_at'        => now(),
        ]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $changes = $response->json('props.recent_changes');
        $this->assertCount(1, $changes);
        $this->assertEquals('departure_gate', $changes[0]['field_name']);
        $this->assertEquals('A10', $changes[0]['old_value']);
        $this->assertEquals('B22', $changes[0]['new_value']);
    }

    public function test_airport_detail_includes_artifacts(): void
    {
        $airport = $this->makeAirport('MUC');
        $source  = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'playwright_table',
            'url'                     => 'https://example.com/muc',
            'scrape_interval_minutes' => 30,
            'is_active'               => true,
        ]);

        $job = ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'success',
            'completed_at'      => now(),
        ]);

        ScrapeArtifact::create([
            'scrape_job_id' => $job->id,
            'artifact_type' => 'screenshot',
            'storage_path'  => 'artifacts/muc/screenshot.png',
            'size_bytes'    => 204800,
            'expires_at'    => now()->addDays(30),
        ]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $artifacts = $response->json('props.artifacts');
        $this->assertCount(1, $artifacts);
        $this->assertEquals('screenshot', $artifacts[0]['artifact_type']);
        $this->assertEquals(204800, $artifacts[0]['size_bytes']);
    }

    public function test_airport_detail_includes_failures(): void
    {
        $airport = $this->makeAirport('DXB');
        $source  = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'arrivals',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com/dxb',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        ParserFailure::create([
            'airport_source_id' => $source->id,
            'failure_type'      => 'hard',
            'severity'          => 'high',
            'error_code'        => 'SELECTOR_NOT_FOUND',
            'error_message'     => 'Could not find .flight-row',
            'status'            => 'open',
        ]);

        $response = $this->inertiaGet("/admin/airports/{$airport->id}");

        $failures = $response->json('props.failures');
        $this->assertCount(1, $failures);
        $this->assertEquals('hard', $failures[0]['failure_type']);
        $this->assertEquals('high', $failures[0]['severity']);
        $this->assertEquals('open', $failures[0]['status']);
    }

    public function test_airport_detail_returns_404_for_unknown_id(): void
    {
        $response = $this->inertiaGet('/admin/airports/99999');
        $response->assertNotFound();
    }
}
