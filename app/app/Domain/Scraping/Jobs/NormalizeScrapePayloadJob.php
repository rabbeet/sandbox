<?php

namespace App\Domain\Scraping\Jobs;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Flights\Jobs\UpdateFlightCurrentStateJob;
use App\Domain\Flights\ValueObjects\CanonicalKey;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NormalizeScrapePayloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $scrapeJobId,
        public readonly array $rows,
    ) {}

    public function handle(): void
    {
        $scrapeJob = ScrapeJob::with('airportSource.airport')->find($this->scrapeJobId);

        if (! $scrapeJob) {
            Log::warning('NormalizeScrapePayloadJob: ScrapeJob not found', ['id' => $this->scrapeJobId]);
            return;
        }

        $source  = $scrapeJob->airportSource;
        $airport = $source?->airport;

        if (! $airport) {
            Log::error('NormalizeScrapePayloadJob: cannot resolve airport', ['scrape_job_id' => $this->scrapeJobId]);
            return;
        }

        $airportIata         = $airport->iata;
        $boardType           = $source->board_type;
        $globalServiceDate   = now()->setTimezone($airport->timezone ?? 'UTC')->toDateString();

        $processed = 0;

        foreach ($this->rows as $row) {
            try {
                // Resolve service_date_local at the system level — this is a system guarantee,
                // not a parser discipline requirement. Priority order:
                //   1. Explicit field from parser row (most reliable, supports multi-day boards)
                //   2. Derived from local scheduled time in the row (handles midnight boundary
                //      without requiring the parser to emit a separate date field)
                //   3. Airport's current date in its timezone (last resort, logged as warning)
                $serviceDateLocal = $this->resolveServiceDate($row, $boardType, $globalServiceDate);

                $canonical = CanonicalKey::build($airportIata, $boardType, $serviceDateLocal, $row);

                $normalizedPayload = $this->normalize($row, $boardType, $serviceDateLocal);

                $snapshot = FlightSnapshot::create([
                    'scrape_job_id'      => $this->scrapeJobId,
                    'canonical_key'      => $canonical,
                    'raw_payload'        => $row,
                    'normalized_payload' => $normalizedPayload,
                ]);

                UpdateFlightCurrentStateJob::dispatch(
                    $this->scrapeJobId,
                    $airport->id,
                    $snapshot->id,    // snapshotId — wired to flight_instance_id inside the job
                    $canonical,
                    $normalizedPayload,
                )->onQueue('state-update');

                $processed++;
            } catch (\Throwable $e) {
                Log::warning('NormalizeScrapePayloadJob: failed to process row', [
                    'scrape_job_id' => $this->scrapeJobId,
                    'error'         => $e->getMessage(),
                    'row'           => $row,
                ]);
            }
        }

        Log::info('NormalizeScrapePayloadJob: complete', [
            'scrape_job_id' => $this->scrapeJobId,
            'total_rows'    => count($this->rows),
            'processed'     => $processed,
        ]);
    }

    /**
     * Normalize a raw scraper row into the standard payload schema.
     * Handles both snake_case and camelCase field names from scraper runtime.
     */
    private function normalize(array $row, string $boardType, string $serviceDateLocal): array
    {
        return [
            'board_type'                 => $boardType,
            'flight_number'              => $row['flight_number'] ?? $row['flightNumber'] ?? '',
            'operating_flight_number'    => $row['operating_flight_number'] ?? $row['operatingFlightNumber'] ?? null,
            'airline_iata'               => $row['airline_iata'] ?? $row['airlineIata'] ?? null,
            'airline_name'               => $row['airline_name'] ?? $row['airlineName'] ?? null,
            'origin_iata'                => $row['origin_iata'] ?? $row['originIata'] ?? $row['from'] ?? null,
            'destination_iata'           => $row['destination_iata'] ?? $row['destinationIata'] ?? $row['to'] ?? null,
            'service_date_local'         => $serviceDateLocal,
            'scheduled_departure_at_utc' => $this->toUtc($row['scheduled_departure_at_utc'] ?? $row['scheduledDeparture'] ?? null),
            'estimated_departure_at_utc' => $this->toUtc($row['estimated_departure_at_utc'] ?? $row['estimatedDeparture'] ?? null),
            'actual_departure_at_utc'    => $this->toUtc($row['actual_departure_at_utc'] ?? $row['actualDeparture'] ?? null),
            'scheduled_arrival_at_utc'   => $this->toUtc($row['scheduled_arrival_at_utc'] ?? $row['scheduledArrival'] ?? null),
            'estimated_arrival_at_utc'   => $this->toUtc($row['estimated_arrival_at_utc'] ?? $row['estimatedArrival'] ?? null),
            'actual_arrival_at_utc'      => $this->toUtc($row['actual_arrival_at_utc'] ?? $row['actualArrival'] ?? null),
            'departure_terminal'         => $row['departure_terminal'] ?? $row['departureTerminal'] ?? null,
            'arrival_terminal'           => $row['arrival_terminal'] ?? $row['arrivalTerminal'] ?? null,
            'departure_gate'             => $row['departure_gate'] ?? $row['departureGate'] ?? $row['gate'] ?? null,
            'arrival_gate'               => $row['arrival_gate'] ?? $row['arrivalGate'] ?? null,
            'baggage_belt'               => $row['baggage_belt'] ?? $row['baggageBelt'] ?? $row['belt'] ?? null,
            'status_raw'                 => $row['status_raw'] ?? $row['status'] ?? null,
            'status_normalized'          => $this->normalizeStatus($row['status_raw'] ?? $row['status'] ?? ''),
        ];
    }

    /**
     * Resolve the operational service date for a flight row.
     *
     * This is a system-level derivation — identity stability must not depend on
     * parsers emitting a specific field. The midnight boundary is handled by
     * extracting the date from the local scheduled time when present.
     *
     * Priority:
     *   1. service_date_local / date field from the row (explicit, most reliable)
     *   2. Date portion of scheduled_*_at_local (system-derived, handles midnight)
     *   3. Airport's current date in its timezone (last resort — emits warning so
     *      the gap is surfaced as a data-quality incident, not silently swallowed)
     */
    private function resolveServiceDate(array $row, string $boardType, string $globalServiceDate): string
    {
        // 1. Explicit field.
        if (! empty($row['service_date_local'])) {
            return $row['service_date_local'];
        }
        if (! empty($row['date'])) {
            return $row['date'];
        }

        // 2. Derive from local scheduled time — handles midnight boundary at the system level.
        //    A flight at 23:50 local will have service_date derived from "23:50" not from now().
        $localTimeField = $boardType === 'arrivals'
            ? ($row['scheduled_arrival_at_local'] ?? null)
            : ($row['scheduled_departure_at_local'] ?? null);

        if (! empty($localTimeField)) {
            try {
                return (new \DateTimeImmutable($localTimeField))->format('Y-m-d');
            } catch (\Throwable) {
                // Malformed local time — fall through to last resort.
            }
        }

        // 3. Last resort: airport's current date.
        //    Logged at warning level so this surfaces as a data-quality incident.
        Log::warning('NormalizeScrapePayloadJob: could not derive service_date_local for row', [
            'scrape_job_id' => $this->scrapeJobId,
            'flight_number' => $row['flight_number'] ?? $row['flightNumber'] ?? '?',
            'board_type'    => $boardType,
            'inferred_date' => $globalServiceDate,
            'action'        => 'parser should emit scheduled_*_at_local or service_date_local',
        ]);

        return $globalServiceDate;
    }

    private function toUtc(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeStatus(?string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        $map = [
            'on time'     => 'on_time',
            'ontime'      => 'on_time',
            'delayed'     => 'delayed',
            'departed'    => 'departed',
            'airborne'    => 'airborne',
            'in flight'   => 'airborne',
            'inflight'    => 'airborne',
            'landed'      => 'landed',
            'arrived'     => 'arrived',
            'baggage'     => 'baggage_claim',
            'gate closed' => 'gate_closed_final',
            'gate open'   => 'boarding',
            'boarding'    => 'boarding',
            'cancelled'   => 'cancelled',
            'canceled'    => 'cancelled',
            'diverted'    => 'diverted',
            'scheduled'   => 'scheduled',
        ];

        $lower = strtolower(trim($raw));

        foreach ($map as $pattern => $normalized) {
            if (str_contains($lower, $pattern)) {
                return $normalized;
            }
        }

        return preg_replace('/[^a-z0-9]+/', '_', $lower);
    }
}
