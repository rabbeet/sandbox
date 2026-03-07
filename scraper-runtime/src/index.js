require('dotenv').config();
const express = require('express');
const { scrapeHandler } = require('./workers/scrapeHandler');
const logger = require('./lib/logger');

const app = express();
app.use(express.json({ limit: '10mb' }));

// Default port matches PHP ScrapeRuntimeClient (scraper.runtime_url: http://localhost:3100)
const PORT = process.env.PORT || 3100;

/**
 * POST /scrape
 *
 * Accepts Laravel ScrapeRuntimeClient payload:
 *   { scrape_job_id, airport_iata, board_type, source_type, url, parser }
 *
 * Returns:
 *   { rows: [...], row_count: int, quality_score: float, artifacts: [...] }
 */
app.post('/scrape', async (req, res) => {
    const { scrape_job_id, airport_iata, board_type, source_type, url, parser } = req.body;

    if (!scrape_job_id || !url || !parser) {
        return res.status(400).json({
            error: 'Missing required fields: scrape_job_id, url, parser',
        });
    }

    logger.info('Scrape request received', { scrape_job_id, airport_iata, board_type, url });

    try {
        const result = await scrapeHandler({
            job_id: scrape_job_id,
            airport_iata,
            board_type,
            source_type,
            url,
            parser_definition: parser,
        });

        logger.info('Scrape completed', {
            scrape_job_id,
            row_count: result.row_count,
            quality_score: result.quality_score,
        });

        res.json(result);
    } catch (err) {
        logger.error('Scrape failed', { scrape_job_id, error: err.message, code: err.code });
        res.status(500).json({ error: err.message, code: err.code || 'SCRAPE_ERROR' });
    }
});

app.get('/health', (_req, res) => res.json({ status: 'ok', port: PORT }));

app.listen(PORT, () => {
    logger.info(`Scraper runtime listening on port ${PORT}`);
});
