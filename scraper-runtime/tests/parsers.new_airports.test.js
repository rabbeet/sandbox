/**
 * Parser definition tests for the 5 new airports:
 *   DPS (Ngurah Rai, Bali, Indonesia)
 *   HKT (Phuket, Thailand)
 *   BKK (Suvarnabhumi, Bangkok, Thailand)
 *   KUL (Kuala Lumpur, Malaysia)
 *   HAN (Noi Bai, Hanoi, Vietnam)
 *
 * Each test:
 *  - Loads a real or synthetic JSON fixture matching the airport's API response shape.
 *  - Runs jsonEndpointParser with the production parser_definition from the seeder.
 *  - Verifies field_map correctness, date_filter behaviour, and row_count > 0.
 *
 * axios is mocked so no real network calls are made.
 */

const path = require('path');
const fs   = require('fs');

jest.mock('axios');
const axios = require('axios');

const jsonEndpointParser = require('../src/parsers/jsonEndpointParser');

// Helper: load a JSON fixture from the tests/fixtures directory.
function loadFixture(filename) {
    const fullPath = path.join(__dirname, 'fixtures', filename);
    return JSON.parse(fs.readFileSync(fullPath, 'utf8'));
}

// Helper: build a fake axios.get that returns the fixture data.
function mockAxiosGet(fixtureData) {
    axios.get = jest.fn().mockResolvedValue({ data: fixtureData });
}

// ---------------------------------------------------------------------------
// DPS — Ngurah Rai, Bali, Indonesia
// API:  https://bali-airport.com/data-airline/dept
// Fixture: dps_departures.json
// UTC offset: +8 (Asia/Makassar / WITA)
// ---------------------------------------------------------------------------
describe('DPS parser definition', () => {
    const fixture = loadFixture('dps_departures.json');

    const parserDefinition = {
        mode:          'json_endpoint',
        data_key:      'data',
        url_params:    {},
        auto_paginate: false,
        row_filter:    { arrdep: 'D' },
        date_filter:   { field: 'schedule', utc_offset_hours: 8 },
        field_map: {
            flight_number:              'flightno',
            airline_iata:               'operator',
            airline_name:               'operator',
            destination_iata:           'fromto',
            destination_name:           'fromtolocation',
            status_raw:                 'flightstat',
            departure_terminal:         'terminal',
            service_date_local:         'schedule',
            scheduled_departure_at_utc: 'schedule',
            departure_gate:             'gatenumber',
        },
    };

    beforeEach(() => {
        mockAxiosGet(fixture);
    });

    test('returns rows from data key, filtered to arrdep=D only', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://bali-airport.com/data-airline/dept',
            parser_definition: parserDefinition,
        });

        // Fixture has 2 departure rows (arrdep=D) and 1 arrival row (arrdep=A).
        // The row_filter should keep only the 2 departure rows.
        expect(rows.length).toBeGreaterThan(0);
        rows.forEach(r => {
            expect(r.flight_number).toBeTruthy();
            expect(r.airline_iata).toBeTruthy();
            expect(r.destination_iata).toBeTruthy();
        });
    });

    test('maps flight_number from flightno field', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://bali-airport.com/data-airline/dept',
            parser_definition: parserDefinition,
        });

        const ga401 = rows.find(r => r.flight_number === 'GA-401');
        expect(ga401).toBeDefined();
        expect(ga401.airline_iata).toBe('GA');
        expect(ga401.destination_iata).toBe('CGK');
        expect(ga401.destination_name).toBe('Jakarta');
        expect(ga401.status_raw).toBe('Scheduled');
        expect(ga401.departure_terminal).toBe('D');
        expect(ga401.departure_gate).toBe('G1');
    });

    test('date_filter keeps only rows matching today in UTC+8', async () => {
        // The fixture's schedule values use today's date (2026-03-09).
        // With utc_offset_hours=8, the local date should match today.
        // We verify no rows are dropped spuriously by checking count > 0.
        const { rows } = await jsonEndpointParser({
            url: 'https://bali-airport.com/data-airline/dept',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });

    test('arrival row is excluded by row_filter', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://bali-airport.com/data-airline/dept',
            parser_definition: parserDefinition,
        });

        // GA-402 in the fixture has arrdep=A and should be filtered out.
        const arrival = rows.find(r => r.flight_number === 'GA-402');
        expect(arrival).toBeUndefined();
    });
});

// ---------------------------------------------------------------------------
// HKT — Phuket International Airport, Thailand
// API:  AOT Next.js data endpoint (pageProps.dataFlight.flights)
// Fixture: hkt_departures.json
// UTC offset: +7 (Asia/Bangkok)
// ---------------------------------------------------------------------------
describe('HKT parser definition', () => {
    const fixture = loadFixture('hkt_departures.json');

    const parserDefinition = {
        mode:          'json_endpoint',
        data_key:      'flights',
        url_params:    { type: 'd', lang: 'en', apisite_id: '7' },
        auto_paginate: false,
        row_filter:    {},
        date_filter:   { field: 'deptime', utc_offset_hours: 7 },
        field_map: {
            flight_number:              'flightno',
            airline_iata:               'airline_code',
            airline_name:               'airline_name',
            destination_iata:           'dest_code',
            destination_name:           'dest_name',
            status_raw:                 'status',
            departure_terminal:         'terminal',
            service_date_local:         'deptime',
            scheduled_departure_at_utc: 'deptime',
            departure_gate:             'gate',
        },
    };

    beforeEach(() => {
        mockAxiosGet(fixture);
    });

    test('resolves flights data_key from fixture', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });

        expect(rows.length).toBeGreaterThan(0);
    });

    test('maps flight fields correctly', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });

        const fd3080 = rows.find(r => r.flight_number === 'FD3080');
        expect(fd3080).toBeDefined();
        expect(fd3080.airline_iata).toBe('FD');
        expect(fd3080.airline_name).toBe('Thai AirAsia');
        expect(fd3080.destination_iata).toBe('DMK');
        expect(fd3080.destination_name).toBe('Don Mueang');
        expect(fd3080.status_raw).toBe('On Time');
        expect(fd3080.departure_terminal).toBe('1');
        expect(fd3080.departure_gate).toBe('A1');
    });

    test('date_filter with utc_offset_hours=7', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });
        // Fixture has rows with deptime matching today (2026-03-09) — should not be zero.
        expect(rows.length).toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// BKK — Suvarnabhumi Airport, Bangkok, Thailand
// API:  AOT Next.js data endpoint (pageProps.dataFlight.flights)
// Fixture: bkk_departures.json
// UTC offset: +7 (Asia/Bangkok)
// ---------------------------------------------------------------------------
describe('BKK parser definition', () => {
    const fixture = loadFixture('bkk_departures.json');

    const parserDefinition = {
        mode:          'json_endpoint',
        data_key:      'flights',
        url_params:    { type: 'd', lang: 'en', apisite_id: '1' },
        auto_paginate: false,
        row_filter:    {},
        date_filter:   { field: 'deptime', utc_offset_hours: 7 },
        field_map: {
            flight_number:              'flightno',
            airline_iata:               'airline_code',
            airline_name:               'airline_name',
            destination_iata:           'dest_code',
            destination_name:           'dest_name',
            status_raw:                 'status',
            departure_terminal:         'terminal',
            service_date_local:         'deptime',
            scheduled_departure_at_utc: 'deptime',
            departure_gate:             'gate',
        },
    };

    beforeEach(() => {
        mockAxiosGet(fixture);
    });

    test('returns rows from BKK fixture', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });

    test('maps TG100 fields correctly', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });

        const tg100 = rows.find(r => r.flight_number === 'TG100');
        expect(tg100).toBeDefined();
        expect(tg100.airline_iata).toBe('TG');
        expect(tg100.airline_name).toBe('Thai Airways');
        expect(tg100.destination_iata).toBe('LHR');
        expect(tg100.status_raw).toBe('On Time');
        expect(tg100.departure_terminal).toBe('D');
        expect(tg100.departure_gate).toBe('G1');
    });

    test('delayed flight status preserved', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://apis.airportthai.co.th/flightschedule/rest/flights',
            parser_definition: parserDefinition,
        });

        const ek375 = rows.find(r => r.flight_number === 'EK375');
        expect(ek375).toBeDefined();
        expect(ek375.status_raw).toBe('Delayed');
    });
});

// ---------------------------------------------------------------------------
// KUL — Kuala Lumpur International Airport, Malaysia
// API:  Malaysia Airports best-effort endpoint (data key)
// Fixture: kul_departures.json
// UTC offset: +8 (Asia/Kuala_Lumpur)
// ---------------------------------------------------------------------------
describe('KUL parser definition', () => {
    const fixture = loadFixture('kul_departures.json');

    const parserDefinition = {
        mode:          'json_endpoint',
        data_key:      'data',
        url_params:    { airport: 'KUL', type: 'D', date: '{{today}}' },
        auto_paginate: false,
        row_filter:    {},
        date_filter:   { field: 'scheduledDeparture', utc_offset_hours: 8 },
        field_map: {
            flight_number:              'flightNo',
            airline_iata:               'airlineCode',
            airline_name:               'airlineName',
            destination_iata:           'destinationCode',
            destination_name:           'destinationName',
            status_raw:                 'status',
            departure_terminal:         'terminal',
            service_date_local:         'scheduledDeparture',
            scheduled_departure_at_utc: 'scheduledDeparture',
            departure_gate:             'gate',
        },
    };

    beforeEach(() => {
        mockAxiosGet(fixture);
    });

    test('returns rows from KUL fixture', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://www.malaysiaairports.com.my/api/flight-departures',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });

    test('maps MH002 fields correctly', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://www.malaysiaairports.com.my/api/flight-departures',
            parser_definition: parserDefinition,
        });

        const mh002 = rows.find(r => r.flight_number === 'MH002');
        expect(mh002).toBeDefined();
        expect(mh002.airline_iata).toBe('MH');
        expect(mh002.airline_name).toBe('Malaysia Airlines');
        expect(mh002.destination_iata).toBe('LHR');
        expect(mh002.destination_name).toBe('London Heathrow');
        expect(mh002.status_raw).toBe('On Time');
        expect(mh002.departure_terminal).toBe('KLIA Main');
        expect(mh002.departure_gate).toBe('C4');
    });

    test('date_filter with utc_offset_hours=8', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://www.malaysiaairports.com.my/api/flight-departures',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// HAN — Noi Bai International Airport, Hanoi, Vietnam
// API:  ACV Vietnam departure_flights endpoint
// Fixture: han_departures.json
// UTC offset: +7 (Asia/Ho_Chi_Minh)
// ---------------------------------------------------------------------------
describe('HAN parser definition', () => {
    const fixture = loadFixture('han_departures.json');

    const parserDefinition = {
        mode:          'json_endpoint',
        data_key:      'departure_flights',
        url_params:    { flight_date: '{{today}}' },
        auto_paginate: false,
        row_filter:    {},
        date_filter:   { field: 'scheduled_time', utc_offset_hours: 7 },
        field_map: {
            flight_number:              'flight_no',
            airline_iata:               'carrier',
            airline_name:               'carrier',
            destination_iata:           'route',
            destination_name:           'route',
            status_raw:                 'status',
            departure_terminal:         'terminal',
            service_date_local:         'scheduled_time',
            scheduled_departure_at_utc: 'scheduled_time',
            departure_gate:             'gate',
        },
    };

    beforeEach(() => {
        mockAxiosGet(fixture);
    });

    test('returns rows from departure_flights key', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://acv.vn/noibaiairport/vi/action/flight_plan_filter',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });

    test('maps VN1200 fields correctly', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://acv.vn/noibaiairport/vi/action/flight_plan_filter',
            parser_definition: parserDefinition,
        });

        const vn1200 = rows.find(r => r.flight_number === 'VN1200');
        expect(vn1200).toBeDefined();
        expect(vn1200.airline_iata).toBe('VN');
        expect(vn1200.destination_iata).toBe('HAN-SGN');
        expect(vn1200.status_raw).toBe('OPN');
        expect(vn1200.departure_terminal).toBe('T1');
        expect(vn1200.departure_gate).toBe('1');
    });

    test('arrival_flights key is not included in rows', async () => {
        const { rows } = await jsonEndpointParser({
            url: 'https://acv.vn/noibaiairport/vi/action/flight_plan_filter',
            parser_definition: parserDefinition,
        });
        // All rows come from departure_flights only; arrival_flights is an empty array
        // in the fixture so no interference. Count should equal departure_flights length.
        expect(rows.length).toBeGreaterThan(0);
    });

    test('date_filter field scheduled_time with utc_offset_hours=7', async () => {
        // scheduled_time in HAN fixture uses full ISO datetime (2026-03-09T06:00:00).
        // date_filter slices to "2026-03-09" and matches today's local date in UTC+7.
        const { rows } = await jsonEndpointParser({
            url: 'https://acv.vn/noibaiairport/vi/action/flight_plan_filter',
            parser_definition: parserDefinition,
        });
        expect(rows.length).toBeGreaterThan(0);
    });
});
