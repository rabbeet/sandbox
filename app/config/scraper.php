<?php

return [
    /*
     * Base URL of the Node.js scraper runtime service.
     * The runtime exposes POST /scrape and GET /health.
     */
    'runtime_url' => env('SCRAPER_RUNTIME_URL', 'http://localhost:3000'),

    /*
     * HTTP timeout in seconds for a single scrape request.
     */
    'timeout_seconds' => (int) env('SCRAPER_TIMEOUT_SECONDS', 120),

    /*
     * IATA codes of airports that should use the high-priority scrape queue.
     */
    'high_priority_airports' => array_filter(
        explode(',', env('SCRAPER_HIGH_PRIORITY_AIRPORTS', 'JFK,LHR,CDG,DXB,LAX,ATL,ORD,HND,PEK,SIN'))
    ),

    /*
     * Minimum acceptable quality_score (0.0–1.0) for a completed scrape.
     * Jobs that complete with a score below this threshold trigger a soft-failure
     * ParserFailure record (failure_type=soft). The scraper runtime computes the
     * quality score as the fraction of rows that have all required fields present
     * and non-null, weighted by field importance as defined in the parser DSL.
     *
     * Operational note: this is a heuristic, not a hard data guarantee. A score
     * above the threshold means "most rows look structurally complete" — it does
     * not validate the semantic correctness of field values.
     */
    'quality_threshold' => (float) env('SCRAPER_QUALITY_THRESHOLD', 0.5),
];
