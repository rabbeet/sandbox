/**
 * Unit tests for scrapeHandler.js — focused on buildArtifactsList artifact expiry.
 *
 * These tests import the module under test directly without instantiating any
 * parsers or browser. All tests are synchronous/unit-level.
 */

// Expose the private buildArtifactsList function by requiring the module and
// using the exported scrapeHandler's closure. Since it's not exported directly,
// we temporarily re-export it in a test-only path, or we test via observable
// behaviour: checking that artifacts in scrapeHandler's return value contain
// expires_at.
//
// We mock the parser modules and qualityScorer to keep the test self-contained.

jest.mock('../src/lib/qualityScorer', () => ({
    calculateQualityScore: () => 1.0,
}));

// Simple mock parsers that return predefined artifacts
const makeHandler = (artifact = {}) => async () => ({ rows: [{ flight_number: 'TS001' }], artifact });

jest.mock('../src/parsers/jsonEndpointParser', () => makeHandler({ html: '<table></table>', html_path: '/tmp/test.html' }));
jest.mock('../src/parsers/htmlTableParser', () => makeHandler({}));
jest.mock('../src/parsers/playwrightTableParser', () => makeHandler({}));
jest.mock('../src/parsers/playwrightCardsParser', () => makeHandler({}));
jest.mock('../src/parsers/customPlaywrightParser', () => makeHandler({
    html: '<div>flights</div>',
    html_path: '/tmp/custom.html',
    screenshot_path: '/tmp/screenshot.png',
    har: { path: '/tmp/trace.har' },
}));

const { scrapeHandler } = require('../src/workers/scrapeHandler');

function parsedDay(isoString) {
    return new Date(isoString);
}

function approxDaysFromNow(isoString, expectedDays) {
    const target = parsedDay(isoString);
    const now = new Date();
    const diffMs = target - now;
    const diffDays = diffMs / (1000 * 60 * 60 * 24);
    // Allow ±0.1 day (about 2 minutes) to account for test execution time
    return Math.abs(diffDays - expectedDays) < 0.1;
}

const baseParams = {
    job_id: 1,
    airport_iata: 'TST',
    board_type: 'departures',
    source_type: 'json_endpoint',
    url: 'https://example.com',
};

describe('buildArtifactsList — expires_at', () => {
    test('html artifact receives a 90-day expires_at', async () => {
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'json_endpoint' },
        });

        const html = result.artifacts.find(a => a.type === 'html');
        expect(html).toBeDefined();
        expect(html.expires_at).toBeDefined();
        expect(approxDaysFromNow(html.expires_at, 90)).toBe(true);
    });

    test('screenshot artifact receives a 30-day expires_at', async () => {
        // custom_playwright parser returns screenshot_path
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'custom_playwright' },
        });

        const screenshot = result.artifacts.find(a => a.type === 'screenshot');
        expect(screenshot).toBeDefined();
        expect(screenshot.expires_at).toBeDefined();
        expect(approxDaysFromNow(screenshot.expires_at, 30)).toBe(true);
    });

    test('har artifact receives a 90-day expires_at', async () => {
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'custom_playwright' },
        });

        const har = result.artifacts.find(a => a.type === 'har');
        expect(har).toBeDefined();
        expect(har.expires_at).toBeDefined();
        expect(approxDaysFromNow(har.expires_at, 90)).toBe(true);
    });

    test('screenshot expires sooner than html/har', async () => {
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'custom_playwright' },
        });

        const screenshot = result.artifacts.find(a => a.type === 'screenshot');
        const html       = result.artifacts.find(a => a.type === 'html');
        const har        = result.artifacts.find(a => a.type === 'har');

        expect(new Date(screenshot.expires_at) < new Date(html.expires_at)).toBe(true);
        expect(new Date(screenshot.expires_at) < new Date(har.expires_at)).toBe(true);
    });

    test('expires_at is a valid ISO 8601 string', async () => {
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'json_endpoint' },
        });

        const html = result.artifacts.find(a => a.type === 'html');
        expect(html.expires_at).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
    });

    test('no artifacts when parser returns empty artifact object', async () => {
        // htmlTableParser mock returns empty artifact
        const result = await scrapeHandler({
            ...baseParams,
            parser_definition: { mode: 'html_table' },
        });

        expect(result.artifacts).toHaveLength(0);
    });
});
