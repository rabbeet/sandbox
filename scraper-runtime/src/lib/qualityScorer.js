/**
 * Calculate a quality score (0.0–1.0) for a set of scraped flight rows.
 *
 * Factors:
 *   - Row count (0 rows → score 0)
 *   - Required field coverage (% of required fields that are non-null)
 *   - Null rate across all fields
 *   - Duplicate rate (by flight_number)
 */

/**
 * Field aliases: parsers may emit camelCase or short names.
 * Normalise to the canonical snake_case field names the normaliser expects
 * before checking required field coverage.
 */
const FIELD_ALIASES = {
    flightNumber:        'flight_number',
    operatingFlightNumber: 'operating_flight_number',
    scheduledDeparture:  'scheduled_departure_at_utc',
    scheduledArrival:    'scheduled_arrival_at_utc',
    estimatedDeparture:  'estimated_departure_at_utc',
    estimatedArrival:    'estimated_arrival_at_utc',
    actualDeparture:     'actual_departure_at_utc',
    actualArrival:       'actual_arrival_at_utc',
    status:              'status_raw',
    gate:                'departure_gate',
    belt:                'baggage_belt',
    from:                'origin_iata',
    to:                  'destination_iata',
};

/**
 * Return a new row object with camelCase / alias field names resolved to their
 * canonical snake_case equivalents. Original keys are preserved so existing
 * fields are not lost.
 */
function normaliseRowAliases(row) {
    const out = { ...row };
    for (const [alias, canonical] of Object.entries(FIELD_ALIASES)) {
        if (out[alias] !== undefined && out[canonical] === undefined) {
            out[canonical] = out[alias];
        }
    }
    return out;
}

/**
 * Default required fields vary by board type to match what the normaliser
 * actually stores (scheduled_departure_at_utc vs scheduled_arrival_at_utc).
 * Parsers can override via parser_definition.required_fields.
 */
function getDefaultRequiredFields(board_type) {
    const scheduledTimeField = board_type === 'arrivals'
        ? 'scheduled_arrival_at_utc'
        : 'scheduled_departure_at_utc';

    return ['flight_number', scheduledTimeField, 'status_raw'];
}

function calculateQualityScore(rows, parser_definition, board_type) {
    if (!rows || rows.length === 0) {
        return 0.0;
    }

    const requiredFields = parser_definition.required_fields
        || getDefaultRequiredFields(board_type || 'departures');

    // Normalise aliases before checking — parsers may emit flightNumber, status, etc.
    const normalisedRows = rows.map(normaliseRowAliases);

    // 1. Required field coverage (weight 0.5)
    let requiredHits = 0;
    for (const row of normalisedRows) {
        for (const field of requiredFields) {
            if (row[field] != null && row[field] !== '') {
                requiredHits++;
            }
        }
    }
    const requiredCoverage = requiredFields.length > 0
        ? requiredHits / (normalisedRows.length * requiredFields.length)
        : 1.0;

    // 2. Overall null rate across all fields (weight 0.3)
    const allFields = Object.keys(normalisedRows[0] || {});
    let nullCount = 0;
    let totalCells = 0;
    for (const row of normalisedRows) {
        for (const field of allFields) {
            totalCells++;
            if (row[field] == null || row[field] === '') {
                nullCount++;
            }
        }
    }
    const nullRate = totalCells > 0 ? nullCount / totalCells : 0;
    const nullScore = 1.0 - nullRate;

    // 3. Duplicate rate by flight_number (weight 0.2)
    // Use normalised rows so flightNumber alias is resolved to flight_number.
    const seenFlightNumbers = new Set();
    let duplicates = 0;
    for (const row of normalisedRows) {
        const key = row.flight_number;
        if (key == null) continue;
        if (seenFlightNumbers.has(key)) {
            duplicates++;
        } else {
            seenFlightNumbers.add(key);
        }
    }
    const duplicateRate = rows.length > 0 ? duplicates / rows.length : 0;
    const duplicateScore = 1.0 - duplicateRate;

    const score = (requiredCoverage * 0.5) + (nullScore * 0.3) + (duplicateScore * 0.2);

    return Math.round(score * 100) / 100;
}

module.exports = { calculateQualityScore, normaliseRowAliases, getDefaultRequiredFields };
