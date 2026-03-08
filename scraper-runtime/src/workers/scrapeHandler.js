const logger = require('../lib/logger');
const { calculateQualityScore } = require('../lib/qualityScorer');

const DSL_MODE_HANDLERS = {
    json_endpoint: require('../parsers/jsonEndpointParser'),
    html_table: require('../parsers/htmlTableParser'),
    playwright_table: require('../parsers/playwrightTableParser'),
    playwright_cards: require('../parsers/playwrightCardsParser'),
    custom_playwright: require('../parsers/customPlaywrightParser'),
};

/**
 * Main scrape handler: dispatches to the correct parser based on DSL mode.
 *
 * @param {{ job_id, airport_iata, board_type, source_type, url, parser_definition }} params
 * @returns {{ rows: object[], row_count: int, quality_score: float, artifacts: object[] }}
 */
async function scrapeHandler({ job_id, airport_iata, board_type, source_type, url, parser_definition }) {
    const mode = parser_definition.mode;

    if (!DSL_MODE_HANDLERS[mode]) {
        throw Object.assign(new Error(`Unknown parser mode: ${mode}`), { code: 'UNKNOWN_MODE' });
    }

    const handler = DSL_MODE_HANDLERS[mode];

    // Parsers return { rows, artifact: { html, screenshot_path, har } }
    const raw = await handler({ source_id: null, url, parser_definition, job_id, board_type });

    const rows = raw.rows || [];
    // Pass board_type so the scorer uses the correct board-specific required fields
    // (scheduled_departure_at_utc for departures, scheduled_arrival_at_utc for arrivals).
    const quality_score = calculateQualityScore(rows, parser_definition, board_type);

    // Normalize artifact (singular) into artifacts[] array expected by PHP
    const artifacts = buildArtifactsList(raw.artifact || {});

    return {
        rows,
        row_count: rows.length,
        quality_score,
        artifacts,
    };
}

/**
 * Convert the parser's { html, screenshot_path, har } into the
 * PHP-expected array of { type, storage_path, size_bytes, expires_at } entries.
 *
 * Retention policy:
 *   screenshots  — 30 days  (large binary; useful for debugging recent regressions only)
 *   html / har   — 90 days  (text; used for parser replays and audit trails)
 *
 * expires_at is an ISO 8601 UTC string. PHP persists it to scrape_artifacts.expires_at
 * and the scrapes:cleanup command uses it to purge expired rows and storage files.
 */
function buildArtifactsList(artifact) {
    const list = [];

    if (artifact.html) {
        list.push({
            type: 'html',
            storage_path: artifact.html_path || null,
            size_bytes: Buffer.byteLength(artifact.html, 'utf8'),
            expires_at: expiresAt(90),
        });
    }

    if (artifact.screenshot_path) {
        list.push({
            type: 'screenshot',
            storage_path: artifact.screenshot_path,
            size_bytes: null,
            expires_at: expiresAt(30),
        });
    }

    if (artifact.har) {
        list.push({
            type: 'har',
            storage_path: artifact.har.path || null,
            size_bytes: null,
            expires_at: expiresAt(90),
        });
    }

    return list;
}

/**
 * Return an ISO 8601 UTC timestamp `days` calendar days from now.
 * @param {number} days
 * @returns {string}
 */
function expiresAt(days) {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString();
}

module.exports = { scrapeHandler };
