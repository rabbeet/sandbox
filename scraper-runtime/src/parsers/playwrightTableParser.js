const { chromium } = require('playwright');
const path = require('path');
const os = require('os');

/**
 * Uses Playwright to scrape a JS-rendered table.
 * parser_definition.table_selector: CSS selector for table
 * parser_definition.column_map: { column_index: field_name }
 */
async function playwrightTableParser({ url, parser_definition, job_id }) {
    const { table_selector = 'table', column_map = {}, wait_for } = parser_definition;

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ recordHar: { path: path.join(os.tmpdir(), `${job_id}.har`) } });
    const page = await context.newPage();

    let html = null;
    let screenshot_path = null;
    let har = null;

    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });

        if (wait_for) {
            await page.waitForSelector(wait_for, { timeout: 30000 });
        }

        html = await page.content();
        screenshot_path = path.join(os.tmpdir(), `${job_id}.png`);
        await page.screenshot({ path: screenshot_path, fullPage: true });

        const rows = await page.evaluate(({ table_selector, column_map }) => {
            const table = document.querySelector(table_selector);
            if (!table) return [];

            const results = [];
            const tableRows = table.querySelectorAll('tbody tr');
            tableRows.forEach(tr => {
                const cells = tr.querySelectorAll('td');
                const row = {};
                for (const [idx, field] of Object.entries(column_map)) {
                    const cell = cells[parseInt(idx)];
                    row[field] = cell ? cell.textContent.trim() : null;
                }
                results.push(row);
            });
            return results;
        }, { table_selector, column_map });

        await context.close();
        har = { path: path.join(os.tmpdir(), `${job_id}.har`) };

        return { rows, artifact: { html, screenshot_path, har } };
    } catch (err) {
        await browser.close();
        throw err;
    } finally {
        await browser.close().catch(() => {});
    }
}

module.exports = playwrightTableParser;
