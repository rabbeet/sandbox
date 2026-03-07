const axios = require('axios');

/**
 * Fetches static HTML and parses a table without Playwright.
 */
async function htmlTableParser({ url, parser_definition }) {
    const { column_map = {}, headers = {} } = parser_definition;

    const response = await axios.get(url, { headers, timeout: 30000 });
    const html = response.data;

    // Simple regex-based table parsing (no DOM available in Node without jsdom)
    // For production, install jsdom or cheerio
    const rows = parseHtmlTable(html, column_map);

    return {
        rows,
        artifact: { html, screenshot_path: null, har: null },
    };
}

function parseHtmlTable(html, column_map) {
    const rows = [];
    const trRegex = /<tr[^>]*>([\s\S]*?)<\/tr>/gi;
    const tdRegex = /<td[^>]*>([\s\S]*?)<\/td>/gi;
    const stripTags = str => str.replace(/<[^>]+>/g, '').trim();

    let trMatch;
    let isFirstRow = true;
    while ((trMatch = trRegex.exec(html)) !== null) {
        if (isFirstRow) { isFirstRow = false; continue; } // skip header row
        const cells = [];
        let tdMatch;
        const tdRegexLocal = /<td[^>]*>([\s\S]*?)<\/td>/gi;
        while ((tdMatch = tdRegexLocal.exec(trMatch[1])) !== null) {
            cells.push(stripTags(tdMatch[1]));
        }
        if (cells.length === 0) continue;
        const row = {};
        for (const [idx, field] of Object.entries(column_map)) {
            row[field] = cells[parseInt(idx)] ?? null;
        }
        rows.push(row);
    }
    return rows;
}

module.exports = htmlTableParser;
