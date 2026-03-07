/**
 * Calculate a quality score (0.0–1.0) for a set of scraped flight rows.
 *
 * Factors:
 *   - Row count (0 rows → score 0)
 *   - Required field coverage (% of required fields that are non-null)
 *   - Null rate across all fields
 *   - Duplicate rate (by flight_number)
 */

// Fields that must be present for a flight row to be considered complete
const REQUIRED_FIELDS = [
    'flight_number',
    'scheduled_time',
    'status_raw',
];

function calculateQualityScore(rows, parser_definition) {
    if (!rows || rows.length === 0) {
        return 0.0;
    }

    const requiredFields = parser_definition.required_fields || REQUIRED_FIELDS;

    // 1. Required field coverage (weight 0.5)
    let requiredHits = 0;
    for (const row of rows) {
        for (const field of requiredFields) {
            if (row[field] != null && row[field] !== '') {
                requiredHits++;
            }
        }
    }
    const requiredCoverage = requiredFields.length > 0
        ? requiredHits / (rows.length * requiredFields.length)
        : 1.0;

    // 2. Overall null rate (weight 0.3)
    const allFields = Object.keys(rows[0] || {});
    let nullCount = 0;
    let totalCells = 0;
    for (const row of rows) {
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
    const seenFlightNumbers = new Set();
    let duplicates = 0;
    for (const row of rows) {
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

module.exports = { calculateQualityScore };
