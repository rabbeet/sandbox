require('dotenv').config();
const express = require('express');
const { scrapeHandler } = require('./workers/scrapeHandler');
const logger = require('./lib/logger');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;

/**
 * POST /scrape
 * Body: { source_id, url, parser_definition, job_id }
 * Returns: { rows: [...], artifact: { html, screenshot_path, har } }
 */
app.post('/scrape', async (req, res) => {
    const { source_id, url, parser_definition, job_id } = req.body;

    if (!source_id || !url || !parser_definition || !job_id) {
        return res.status(400).json({ error: 'Missing required fields: source_id, url, parser_definition, job_id' });
    }

    logger.info('Scrape request received', { source_id, job_id, url });

    try {
        const result = await scrapeHandler({ source_id, url, parser_definition, job_id });
        logger.info('Scrape completed', { source_id, job_id, row_count: result.rows.length });
        res.json(result);
    } catch (err) {
        logger.error('Scrape failed', { source_id, job_id, error: err.message });
        res.status(500).json({ error: err.message, code: err.code || 'SCRAPE_ERROR' });
    }
});

app.get('/health', (_req, res) => res.json({ status: 'ok' }));

app.listen(PORT, () => {
    logger.info(`Scraper runtime listening on port ${PORT}`);
});
