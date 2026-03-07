<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportsIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Send request as an Inertia XHR to get JSON props instead of full HTML. */
    private function inertiaGet(string $url)
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
        ])->get($url);
    }

    public function test_airports_index_returns_inertia_response(): void
    {
        $response = $this->inertiaGet('/admin/airports');

        $response->assertOk()
            ->assertHeader('X-Inertia');
    }

    public function test_airports_index_includes_airports_with_health_stats(): void
    {
        $airport = Airport::create([
            'iata'      => 'JFK',
            'name'      => 'John F Kennedy Intl',
            'city'      => 'New York',
            'country'   => 'US',
            'timezone'  => 'America/New_York',
            'is_active' => true,
        ]);

        $source = AirportSource::create([
            'airport_id'               => $airport->id,
            'board_type'               => 'departures',
            'source_type'              => 'json_endpoint',
            'url'                      => 'https://example.com/jfk/departures',
            'scrape_interval_minutes'  => 15,
            'is_active'                => true,
        ]);

        // Add a scrape job
        ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'success',
            'row_count'         => 42,
            'completed_at'      => now()->subMinutes(30),
        ]);

        // Add an active flight
        FlightCurrent::create([
            'airport_id'         => $airport->id,
            'canonical_key'      => 'JFK:departures:2026-03-07:TK001:0900',
            'board_type'         => 'departures',
            'flight_number'      => 'TK001',
            'service_date_local' => '2026-03-07',
            'status_normalized'  => 'on_time',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
        ]);

        $response = $this->inertiaGet('/admin/airports');

        $response->assertOk();

        $data = $response->json('props.airports');

        $this->assertCount(1, $data);
        $this->assertEquals('JFK', $data[0]['iata']);
        $this->assertEquals(1, $data[0]['active_flights_count']);
        $this->assertCount(1, $data[0]['sources']);
        $this->assertEquals('departures', $data[0]['sources'][0]['board_type']);
        $this->assertEquals('json_endpoint', $data[0]['sources'][0]['source_type']);
        $this->assertNotNull($data[0]['sources'][0]['last_success_at']);
        $this->assertEquals(100.0, $data[0]['sources'][0]['success_rate_24h']);
        $this->assertEquals(42, $data[0]['sources'][0]['latest_row_count']);
    }

    public function test_airports_index_handles_airport_with_no_sources(): void
    {
        Airport::create([
            'iata'      => 'LAX',
            'name'      => 'Los Angeles Intl',
            'city'      => 'Los Angeles',
            'country'   => 'US',
            'timezone'  => 'America/Los_Angeles',
            'is_active' => true,
        ]);

        $response = $this->inertiaGet('/admin/airports');

        $response->assertOk();
        $data = $response->json('props.airports');
        $this->assertCount(1, $data);
        $this->assertCount(0, $data[0]['sources']);
        $this->assertEquals(0, $data[0]['active_flights_count']);
    }

    public function test_airports_index_shows_null_success_rate_when_no_jobs(): void
    {
        $airport = Airport::create([
            'iata'      => 'LHR',
            'name'      => 'Heathrow',
            'city'      => 'London',
            'country'   => 'GB',
            'timezone'  => 'Europe/London',
            'is_active' => true,
        ]);

        AirportSource::create([
            'airport_id'               => $airport->id,
            'board_type'               => 'arrivals',
            'source_type'              => 'html_table',
            'url'                      => 'https://example.com/lhr/arrivals',
            'scrape_interval_minutes'  => 15,
            'is_active'                => true,
        ]);

        $response = $this->inertiaGet('/admin/airports');
        $data = $response->json('props.airports');

        $this->assertNull($data[0]['sources'][0]['success_rate_24h']);
        $this->assertNull($data[0]['sources'][0]['last_success_at']);
        $this->assertNull($data[0]['sources'][0]['last_failure_at']);
    }
}
