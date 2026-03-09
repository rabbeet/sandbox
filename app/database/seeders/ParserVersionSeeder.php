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
            [
                'iata' => 'DPS',
                'icao' => 'WADD',
                'name' => 'Ngurah Rai International Airport',
                'city' => 'Bali',
                'country' => 'ID',
                'timezone' => 'Asia/Makassar',
            ],
            [
                'iata' => 'HKT',
                'icao' => 'VTSP',
                'name' => 'Phuket International Airport',
                'city' => 'Phuket',
                'country' => 'TH',
                'timezone' => 'Asia/Bangkok',
            ],
            [
                'iata' => 'BKK',
                'icao' => 'VTBS',
                'name' => 'Suvarnabhumi Airport',
                'city' => 'Bangkok',
                'country' => 'TH',
                'timezone' => 'Asia/Bangkok',
            ],
            [
                'iata' => 'KUL',
                'icao' => 'WMKK',
                'name' => 'Kuala Lumpur International Airport',
                'city' => 'Kuala Lumpur',
                'country' => 'MY',
                'timezone' => 'Asia/Kuala_Lumpur',
            ],
            [
                'iata' => 'HAN',
                'icao' => 'VVNB',
                'name' => 'Noi Bai International Airport',
                'city' => 'Hanoi',
                'country' => 'VN',
                'timezone' => 'Asia/Ho_Chi_Minh',
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

            // DPS: JSON endpoint (bali-airport.com InJourney platform, departures)
            // API returns a direct JSON array (data key) with today's departures only.
            // Fields: flightno, operator, fromto (destination IATA), fromtolocation,
            //         flightstat, schedule (local datetime), terminal, gatenumber, arrdep.
            // row_filter on arrdep=D selects departures only (endpoint also used for arrivals).
            if ($airport->iata === 'DPS') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://bali-airport.com/data-airline/dept',
                        'scrape_interval_minutes' => 60,
                        'is_active' => true,
                    ]
                );
                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'data',
                    'url_params'    => [],
                    'auto_paginate' => false,
                    'row_filter'    => ['arrdep' => 'D'],
                    'date_filter'   => ['field' => 'schedule', 'utc_offset_hours' => 8],
                    'field_map'     => [
                        'flight_number'              => 'flightno',
                        'airline_iata'               => 'operator',
                        'airline_name'               => 'operator',
                        'destination_iata'           => 'fromto',
                        'destination_name'           => 'fromtolocation',
                        'status_raw'                 => 'flightstat',
                        'departure_terminal'         => 'terminal',
                        'service_date_local'         => 'schedule',
                        'scheduled_departure_at_utc' => 'schedule',
                        'departure_gate'             => 'gatenumber',
                    ],
                ]);
            }

            // HKT: JSON endpoint (Airports of Thailand – Phuket, apisite_id=7)
            // AOT uses a protected GraphQL API at apis.airportthai.co.th/flightschedule/
            // with apisite_id=7 for HKT. The API requires client-side headers/auth.
            // This is a best-effort implementation referencing the AOT API pattern.
            if ($airport->iata === 'HKT') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://phuket.airportthai.co.th/_next/data/F-CKQvl-Ix6VT-KKaFV1v/flight.json',
                        'scrape_interval_minutes' => 60,
                        'is_active' => true,
                    ]
                );
                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'flights',
                    'url_params'    => ['type' => 'd', 'lang' => 'en', 'apisite_id' => '7'],
                    'auto_paginate' => false,
                    'row_filter'    => [],
                    'date_filter'   => ['field' => 'deptime', 'utc_offset_hours' => 7],
                    'field_map'     => [
                        'flight_number'              => 'flightno',
                        'airline_iata'               => 'airline_code',
                        'airline_name'               => 'airline_name',
                        'destination_iata'           => 'dest_code',
                        'destination_name'           => 'dest_name',
                        'status_raw'                 => 'status',
                        'departure_terminal'         => 'terminal',
                        'service_date_local'         => 'deptime',
                        'scheduled_departure_at_utc' => 'deptime',
                        'departure_gate'             => 'gate',
                    ],
                ]);
            }

            // BKK: JSON endpoint (Airports of Thailand – Suvarnabhumi, apisite_id=1)
            // AOT uses a protected GraphQL API at apis.airportthai.co.th/flightschedule/
            // with apisite_id=1 for BKK. The API requires client-side headers/auth.
            // This is a best-effort implementation referencing the AOT REST-style endpoint.
            if ($airport->iata === 'BKK') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://apis.airportthai.co.th/flightschedule/rest/flights',
                        'scrape_interval_minutes' => 60,
                        'is_active' => true,
                    ]
                );
                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'flights',
                    'url_params'    => ['type' => 'd', 'lang' => 'en', 'apisite_id' => '1'],
                    'auto_paginate' => false,
                    'row_filter'    => [],
                    'date_filter'   => ['field' => 'deptime', 'utc_offset_hours' => 7],
                    'field_map'     => [
                        'flight_number'              => 'flightno',
                        'airline_iata'               => 'airline_code',
                        'airline_name'               => 'airline_name',
                        'destination_iata'           => 'dest_code',
                        'destination_name'           => 'dest_name',
                        'status_raw'                 => 'status',
                        'departure_terminal'         => 'terminal',
                        'service_date_local'         => 'deptime',
                        'scheduled_departure_at_utc' => 'deptime',
                        'departure_gate'             => 'gate',
                    ],
                ]);
            }

            // KUL: JSON endpoint (Malaysia Airports – KLIA)
            // Malaysia Airports (malaysiairports.com.my) uses Next.js App Router with
            // server components; no public JSON flight API was discoverable without
            // client-side JavaScript execution. This is a best-effort stub.
            if ($airport->iata === 'KUL') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://www.malaysiaairports.com.my/api/flight-departures',
                        'scrape_interval_minutes' => 60,
                        'is_active' => true,
                    ]
                );
                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'data',
                    'url_params'    => ['airport' => 'KUL', 'type' => 'D', 'date' => '{{today}}'],
                    'auto_paginate' => false,
                    'row_filter'    => [],
                    'date_filter'   => ['field' => 'scheduledDeparture', 'utc_offset_hours' => 8],
                    'field_map'     => [
                        'flight_number'              => 'flightNo',
                        'airline_iata'               => 'airlineCode',
                        'airline_name'               => 'airlineName',
                        'destination_iata'           => 'destinationCode',
                        'destination_name'           => 'destinationName',
                        'status_raw'                 => 'status',
                        'departure_terminal'         => 'terminal',
                        'service_date_local'         => 'scheduledDeparture',
                        'scheduled_departure_at_utc' => 'scheduledDeparture',
                        'departure_gate'             => 'gate',
                    ],
                ]);
            }

            // HAN: JSON endpoint (ACV – Noi Bai International Airport, Hanoi)
            // ACV Vietnam uses https://acv.vn/noibaiairport/vi/action/flight_plan_filter
            // which returns { departure_flights: [...], arrival_flights: [...] }.
            // The API appears rate-limited / session-protected in server-side curl tests.
            // Field: route (format "HAN-XXX"), flight_no, carrier, scheduled_time, status, gate.
            if ($airport->iata === 'HAN') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://acv.vn/noibaiairport/vi/action/flight_plan_filter',
                        'scrape_interval_minutes' => 60,
                        'is_active' => true,
                    ]
                );
                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'departure_flights',
                    'url_params'    => ['flight_date' => '{{today}}'],
                    'auto_paginate' => false,
                    'row_filter'    => [],
                    'date_filter'   => ['field' => 'scheduled_time', 'utc_offset_hours' => 7],
                    'field_map'     => [
                        'flight_number'              => 'flight_no',
                        'airline_iata'               => 'carrier',
                        'airline_name'               => 'carrier',
                        'destination_iata'           => 'route',
                        'destination_name'           => 'route',
                        'status_raw'                 => 'status',
                        'departure_terminal'         => 'terminal',
                        'service_date_local'         => 'scheduled_time',
                        'scheduled_departure_at_utc' => 'scheduled_time',
                        'departure_gate'             => 'gate',
                    ],
                ]);
            }

            // SVO: JSON endpoint (Bitrix timetable, departures)
            if ($airport->iata === 'SVO') {
                $source = AirportSource::firstOrCreate(
                    ['airport_id' => $airport->id, 'board_type' => 'departures'],
                    [
                        'source_type' => 'json_endpoint',
                        'url' => 'https://www.svo.aero/bitrix/timetable/',
                        'scrape_interval_minutes' => 15,
                        'is_active' => true,
                    ]
                );

                $this->createParserVersion($source, 1, [
                    'mode'          => 'json_endpoint',
                    'data_key'      => 'items',
                    'url_params'    => ['perPage' => '1000', 'page' => '1'],
                    'auto_paginate' => true,
                    'row_filter'    => ['ad' => 'D'],
                    'date_filter'   => ['field' => 'dat', 'utc_offset_hours' => 3],
                    'field_map'   => [
                        'airline_iata'               => 'co.code',
                        'airline_name'               => 'co.name',
                        'destination_iata'           => 'mar2.iata',
                        'destination_name'           => 'mar2.name_en',
                        'status_raw'                 => 'st.name_en',
                        'departure_terminal'         => 'trm.name_en',
                        'service_date_local'         => 'dat',
                        'scheduled_departure_at_utc' => 't_st',
                        'estimated_departure_at_utc' => 't_et',
                        'actual_departure_at_utc'    => 't_at',
                        'departure_gate'             => 'gate_id',
                    ],
                    'computed_fields' => ['flight_number' => '{{co.code}}{{flt}}'],
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
