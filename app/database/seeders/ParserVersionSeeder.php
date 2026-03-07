<?php

namespace Database\Seeders;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use Illuminate\Database\Seeder;

class ParserVersionSeeder extends Seeder
{
    public function run(): void
    {
        // Seed example airports
        $airports = [
            [
                'iata' => 'JFK',
                'icao' => 'KJFK',
                'name' => 'John F. Kennedy International Airport',
                'city' => 'New York',
                'country' => 'US',
                'timezone' => 'America/New_York',
            ],
            [
                'iata' => 'LHR',
                'icao' => 'EGLL',
                'name' => 'London Heathrow Airport',
                'city' => 'London',
                'country' => 'GB',
                'timezone' => 'Europe/London',
            ],
            [
                'iata' => 'SVO',
                'icao' => 'UUEE',
                'name' => 'Sheremetyevo International Airport',
                'city' => 'Moscow',
                'country' => 'RU',
                'timezone' => 'Europe/Moscow',
            ],
        ];

        foreach ($airports as $airportData) {
            $airport = Airport::firstOrCreate(['iata' => $airportData['iata']], $airportData);

            // JFK: JSON endpoint parser (departures)
            if ($airport->iata === 'JFK') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://www.jfkairport.com/api/flights/departures',
                        'scrape_interval_minutes' => 10,
                        'is_active' => true,
                    ]
                );

                $this->createParserVersion($source, 1, [
                    'mode' => 'json_endpoint',
                    'request' => [
                        'method' => 'GET',
                        'headers' => ['Accept' => 'application/json'],
                    ],
                    'response' => [
                        'flights_path' => 'data.flights',
                    ],
                    'fields' => [
                        'flight_number' => 'flightNumber',
                        'airline_iata' => 'airlineCode',
                        'airline_name' => 'airlineName',
                        'destination_iata' => 'destinationCode',
                        'destination_name' => 'destinationCity',
                        'scheduled_departure_at_utc' => 'scheduledDeparture',
                        'estimated_departure_at_utc' => 'estimatedDeparture',
                        'actual_departure_at_utc' => 'actualDeparture',
                        'departure_terminal' => 'terminal',
                        'departure_gate' => 'gate',
                        'status_raw' => 'flightStatus',
                    ],
                ]);
            }

            // LHR: HTML table parser (arrivals)
            if ($airport->iata === 'LHR') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'arrivals'],
                    [
                        'source_type' => 'html_table',
                        'url' => 'https://www.heathrow.com/arrivals',
                        'scrape_interval_minutes' => 15,
                        'is_active' => true,
                    ]
                );

                $this->createParserVersion($source, 1, [
                    'mode' => 'html_table',
                    'table_selector' => 'table.flights-table',
                    'header_row' => 0,
                    'columns' => [
                        0 => 'scheduled_arrival_local',
                        1 => 'origin_name',
                        2 => 'flight_number',
                        3 => 'airline_name',
                        4 => 'arrival_terminal',
                        5 => 'status_raw',
                        6 => 'baggage_belt',
                    ],
                    'transforms' => [
                        'scheduled_arrival_local' => ['type' => 'parse_time', 'format' => 'H:i'],
                    ],
                ]);
            }

            // SVO: Playwright cards parser (departures)
            if ($airport->iata === 'SVO') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'playwright_cards',
                        'url' => 'https://www.svo.aero/en/timetable/departure/',
                        'scrape_interval_minutes' => 15,
                        'is_active' => true,
                    ]
                );

                $this->createParserVersion($source, 1, [
                    'mode' => 'playwright_cards',
                    'wait_for' => '.flight-card',
                    'card_selector' => '.flight-card',
                    'fields' => [
                        'flight_number' => ['selector' => '.flight-number', 'attr' => 'text'],
                        'airline_name' => ['selector' => '.airline-name', 'attr' => 'text'],
                        'destination_iata' => ['selector' => '.destination-code', 'attr' => 'text'],
                        'scheduled_departure_local' => ['selector' => '.scheduled-time', 'attr' => 'text'],
                        'departure_terminal' => ['selector' => '.terminal', 'attr' => 'text'],
                        'departure_gate' => ['selector' => '.gate', 'attr' => 'text'],
                        'status_raw' => ['selector' => '.status', 'attr' => 'text'],
                    ],
                ]);
            }
        }
    }

    private function createParserVersion(AirportSource $source, int $version, array $definition): ParserVersion
    {
        $parserVersion = ParserVersion::firstOrCreate(
            ['airport_source_id' => $source->id, 'version' => $version],
            [
                'definition' => $definition,
                'is_active' => true,
                'activated_at' => now(),
            ]
        );

        // Set as active on the source
        $source->update(['active_parser_version_id' => $parserVersion->id]);

        return $parserVersion;
    }
}
