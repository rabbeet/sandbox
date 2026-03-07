<?php

namespace App\Domain\Scraping\Services;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeRuntimeClient
{
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('scraper.runtime_url', 'http://localhost:3100'), '/');
        $this->timeoutSeconds = (int) config('scraper.timeout_seconds', 120);
    }

    /**
     * Send a scrape request to the Node.js scraper runtime.
     *
     * @return array{rows: array, row_count: int, quality_score: float, artifacts: array}
     * @throws \RuntimeException on failure
     */
    public function scrape(AirportSource $source, ParserVersion $parserVersion, int $scrapeJobId): array
    {
        $payload = [
            'scrape_job_id' => $scrapeJobId,
            'airport_iata'  => $source->airport->iata_code,
            'board_type'    => $source->board_type,
            'source_type'   => $source->source_type,
            'url'           => $source->url,
            'parser'        => $parserVersion->definition,
        ];

        Log::info('ScrapeRuntimeClient: dispatching scrape', [
            'scrape_job_id' => $scrapeJobId,
            'source_id'     => $source->id,
            'airport'       => $source->airport->iata_code,
        ]);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/scrape", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("scraper_runtime_unreachable: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->body();
            throw new \RuntimeException("scraper_runtime_http_{$status}: {$body}");
        }

        $data = $response->json();

        if (! isset($data['rows'])) {
            throw new \RuntimeException('scraper_runtime_invalid_response: missing rows key');
        }

        return [
            'rows'          => $data['rows'] ?? [],
            'row_count'     => $data['row_count'] ?? count($data['rows']),
            'quality_score' => (float) ($data['quality_score'] ?? 0.0),
            'artifacts'     => $data['artifacts'] ?? [],
        ];
    }
}
