<?php

namespace Tests\Feature\Api;

use App\Domain\Airports\Models\Airport;
use App\Domain\Flights\Models\FlightCurrent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightSearchTest extends TestCase
{
    use RefreshDatabase;

    private Airport $airport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::create([
            'iata'      => 'JFK',
            'name'      => 'John F Kennedy Intl',
            'city'      => 'New York',
            'country'   => 'US',
            'timezone'  => 'America/New_York',
            'is_active' => true,
        ]);
    }

    private function makeFlight(array $overrides = []): FlightCurrent
    {
        return FlightCurrent::create(array_merge([
            'airport_id'         => $this->airport->id,
            'canonical_key'      => 'JFK:departures:2026-03-07:TK001:0900',
            'board_type'         => 'departures',
            'flight_number'      => 'TK001',
            'airline_iata'       => 'TK',
            'airline_name'       => 'Turkish Airlines',
            'destination_iata'   => 'IST',
            'service_date_local' => '2026-03-07',
            'status_normalized'  => 'on_time',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
        ], $overrides));
    }

    public function test_search_returns_matching_flights(): void
    {
        $this->makeFlight();

        $response = $this->getJson('/api/flights/search?flight=TK001');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flight_number', 'TK001');
    }

    public function test_search_requires_flight_param(): void
    {
        $this->getJson('/api/flights/search')->assertStatus(422);
    }

    public function test_show_returns_single_flight(): void
    {
        $flight = $this->makeFlight();

        $this->getJson("/api/flights/{$flight->id}")
            ->assertOk()
            ->assertJsonPath('data.flight_number', 'TK001')
            ->assertJsonPath('data.airport.iata', 'JFK');
    }

    public function test_departures_board_for_airport(): void
    {
        $this->makeFlight(['service_date_local' => '2026-03-07']);

        $response = $this->getJson('/api/airports/JFK/departures?date=2026-03-07');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.board_type', 'departures');
    }

    public function test_arrivals_board_returns_only_arrivals(): void
    {
        $this->makeFlight(['board_type' => 'departures', 'canonical_key' => 'JFK:departures:2026-03-07:TK001:0900']);
        $this->makeFlight([
            'board_type'    => 'arrivals',
            'canonical_key' => 'JFK:arrivals:2026-03-07:BA200:1400',
            'flight_number' => 'BA200',
            'service_date_local' => '2026-03-07',
        ]);

        $response = $this->getJson('/api/airports/JFK/arrivals?date=2026-03-07');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flight_number', 'BA200');
    }

    public function test_departures_returns_404_for_unknown_airport(): void
    {
        $this->getJson('/api/airports/XYZ/departures')->assertNotFound();
    }

    public function test_disruptions_returns_delayed_flights(): void
    {
        $this->makeFlight([
            'status_normalized' => 'delayed',
            'delay_minutes'     => 45,
        ]);
        $this->makeFlight([
            'canonical_key'     => 'JFK:departures:2026-03-07:TK002:1000',
            'flight_number'     => 'TK002',
            'status_normalized' => 'on_time',
        ]);

        $response = $this->getJson('/api/disruptions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status_normalized', 'delayed');
    }
}
