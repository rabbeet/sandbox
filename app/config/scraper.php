<?php

return [
    /*
     * Base URL of the Node.js scraper runtime service.
     * The runtime exposes POST /scrape and GET /health.
     */
    'runtime_url' => env('SCRAPER_RUNTIME_URL', 'http://localhost:3100'),

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
];
