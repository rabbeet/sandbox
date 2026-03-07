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
    const raw = await handler({ source_id: null, url, parser_definition, job_id });

    const rows = raw.rows || [];
    const quality_score = calculateQualityScore(rows, parser_definition);

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
 * PHP-expected array of { type, storage_path, size_bytes } entries.
 */
function buildArtifactsList(artifact) {
    const list = [];

    if (artifact.html) {
        list.push({
            type: 'html',
            storage_path: artifact.html_path || null,
            size_bytes: Buffer.byteLength(artifact.html, 'utf8'),
        });
    }

    if (artifact.screenshot_path) {
        list.push({
            type: 'screenshot',
            storage_path: artifact.screenshot_path,
            size_bytes: null,
        });
    }

    if (artifact.har) {
        list.push({
            type: 'har',
            storage_path: artifact.har.path || null,
            size_bytes: null,
        });
    }

    return list;
}

module.exports = { scrapeHandler };
