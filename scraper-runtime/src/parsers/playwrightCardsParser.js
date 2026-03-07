const { chromium } = require('playwright');
const path = require('path');
const os = require('os');

/**
 * Scrapes card-style flight listings (div/li cards instead of table rows).
 * parser_definition.card_selector: CSS selector for each card
 * parser_definition.field_selectors: { field_name: CSS selector relative to card }
 */
async function playwrightCardsParser({ url, parser_definition, job_id }) {
    const { card_selector, field_selectors = {}, wait_for } = parser_definition;

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });

        if (wait_for) {
            await page.waitForSelector(wait_for, { timeout: 30000 });
        }

        const html = await page.content();
        const screenshot_path = path.join(os.tmpdir(), `${job_id}.png`);
        await page.screenshot({ path: screenshot_path, fullPage: true });

        const rows = await page.evaluate(({ card_selector, field_selectors }) => {
            const cards = document.querySelectorAll(card_selector);
            return Array.from(cards).map(card => {
                const row = {};
                for (const [field, selector] of Object.entries(field_selectors)) {
                    const el = card.querySelector(selector);
                    row[field] = el ? el.textContent.trim() : null;
                }
                return row;
            });
        }, { card_selector, field_selectors });

        return { rows, artifact: { html, screenshot_path, har: null } };
    } finally {
        await browser.close();
    }
}

module.exports = playwrightCardsParser;
