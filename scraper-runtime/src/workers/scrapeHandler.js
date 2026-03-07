const { chromium } = require('playwright');
const logger = require('../lib/logger');

const DSL_MODE_HANDLERS = {
    json_endpoint: require('../parsers/jsonEndpointParser'),
    html_table: require('../parsers/htmlTableParser'),
    playwright_table: require('../parsers/playwrightTableParser'),
    playwright_cards: require('../parsers/playwrightCardsParser'),
    custom_playwright: require('../parsers/customPlaywrightParser'),
};

/**
 * Main scrape handler: dispatches to the correct parser based on DSL mode.
 * @param {{ source_id, url, parser_definition, job_id }} params
 * @returns {{ rows: object[], artifact: { html: string, screenshot_path: string|null, har: object|null } }}
 */
async function scrapeHandler({ source_id, url, parser_definition, job_id }) {
    const mode = parser_definition.mode;

    if (!DSL_MODE_HANDLERS[mode]) {
        throw Object.assign(new Error(`Unknown parser mode: ${mode}`), { code: 'UNKNOWN_MODE' });
    }

    const handler = DSL_MODE_HANDLERS[mode];
    return handler({ source_id, url, parser_definition, job_id });
}

module.exports = { scrapeHandler };
