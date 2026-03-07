const { chromium } = require('playwright');
const path = require('path');
const os = require('os');

/**
 * Executes a custom JavaScript extraction function defined in parser_definition.script.
 * The script receives { page } and must return an array of row objects.
 * WARNING: Only trusted, version-controlled scripts should be executed here.
 */
async function customPlaywrightParser({ url, parser_definition, job_id }) {
    const { script, wait_for } = parser_definition;

    if (!script) {
        throw Object.assign(new Error('custom_playwright mode requires parser_definition.script'), { code: 'INVALID_CONFIG' });
    }

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

        // Execute the stored extraction script in the Node context with page access
        const extractFn = new Function('page', `return (async (page) => { ${script} })(page)`);
        const rows = await extractFn(page);

        return { rows, artifact: { html, screenshot_path, har: null } };
    } finally {
        await browser.close();
    }
}

module.exports = customPlaywrightParser;
