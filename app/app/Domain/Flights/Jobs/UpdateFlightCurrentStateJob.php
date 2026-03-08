<?php

namespace App\Domain\Flights\Jobs;

use App\Domain\Flights\Models\FlightChange;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Scraping\Models\FlightSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateFlightCurrentStateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /** Fields that trigger a flight_changes record when they differ. */
    private const TRACKED_FIELDS = [
        'estimated_departure_at_utc',
        'actual_departure_at_utc',
        'estimated_arrival_at_utc',
        'actual_arrival_at_utc',
        'departure_terminal',
        'arrival_terminal',
        'departure_gate',
        'arrival_gate',
        'baggage_belt',
        'status_raw',
        'status_normalized',
    ];

    private const DEPARTED_STATUSES = ['departed', 'gate_closed_final', 'airborne'];
    private const ARRIVED_STATUSES  = ['arrived', 'landed', 'baggage_claim'];

    public function __construct(
        public readonly int $scrapeJobId,
        public readonly int $airportId,
        public readonly int $snapshotId,
        public readonly string $canonicalKey,
        public readonly array $normalizedPayload,
    ) {}

    public function handle(): void
    {
        $payload      = $this->normalizedPayload;
        $isCompleted  = $this->resolveIsCompleted($payload);
        $delayMinutes = $this->resolveDelayMinutes($payload);

        try {
            DB::transaction(function () use ($payload, $isCompleted, $delayMinutes) {
                // --- Step 1: Resolve stable logical identity ---
                //
                // FlightInstance is the single source of truth for flight identity.
                // The 4-tuple (airport_id, board_type, flight_number, service_date_local)
                // has a UNIQUE constraint in the DB, so firstOrCreate is deterministic.
                //
                // lockForUpdate() on the SELECT prevents two concurrent workers from both
                // concluding "not found" and racing to insert. If they do race past the
                // lock, UniqueConstraintViolationException is caught below and the job
                // retries — on retry firstOrCreate finds the row.
                $instance = $this->resolveOrCreateInstance($payload);

                // --- Step 2: Back-fill snapshot with instance id ---
                //
                // The snapshot was persisted before this job ran (in NormalizeScrapePayloadJob).
                // We set flight_instance_id here, inside the same transaction, so that snapshot
                // history is always anchored to the stable identity record.
                FlightSnapshot::where('id', $this->snapshotId)
                    ->whereNull('flight_instance_id')
                    ->update(['flight_instance_id' => $instance->id]);

                // --- Step 3: Find or create FlightCurrent by flight_instance_id ---
                //
                // FlightCurrent is a mutable projection of the latest state.
                // It is keyed by flight_instance_id, not canonical_key, so that the
                // projection survives any canonical_key drift without losing history.
                $existing = FlightCurrent::where('flight_instance_id', $instance->id)
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    // Safety net: check canonical_key in case a row exists from before
                    // flight_instance_id was wired (e.g. a previous deploy). This path
                    // will become unreachable once all rows have flight_instance_id set.
                    $existing = FlightCurrent::where('canonical_key', $this->canonicalKey)
                        ->whereNull('flight_instance_id')
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        // Adopt: wire the existing row to the resolved instance.
                        $existing->update(['flight_instance_id' => $instance->id]);
                        Log::info('UpdateFlightCurrentStateJob: adopted legacy row into instance', [
                            'canonical_key'      => $this->canonicalKey,
                            'flight_instance_id' => $instance->id,
                        ]);
                    }
                }

                if (! $existing) {
                    FlightCurrent::create([
                        ...$this->buildAttributes($payload),
                        'flight_instance_id' => $instance->id,
                        'airport_id'         => $this->airportId,
                        'canonical_key'      => $this->canonicalKey,
                        'delay_minutes'      => $delayMinutes,
                        'is_active'          => true,
                        'is_completed'       => $isCompleted,
                        'last_seen_at'       => now(),
                        'last_changed_at'    => now(),
                    ]);

                    Log::info('UpdateFlightCurrentStateJob: created flight current', [
                        'canonical_key'      => $this->canonicalKey,
                        'flight_instance_id' => $instance->id,
                    ]);
                    return;
                }

                $changes = $this->detectChanges($existing, $payload);

                $updates = [
                    ...$this->buildAttributes($payload),
                    'canonical_key'  => $this->canonicalKey,
                    'delay_minutes'  => $delayMinutes,
                    'is_completed'   => $isCompleted,
                    'last_seen_at'   => now(),
                ];

                if (! empty($changes)) {
                    $updates['last_changed_at'] = now();
                }

                $existing->update($updates);

                foreach ($changes as $field => [$oldVal, $newVal]) {
                    FlightChange::create([
                        'flight_current_id' => $existing->id,
                        'scrape_job_id'     => $this->scrapeJobId,
                        'field_name'        => $field,
                        'old_value'         => $oldVal,
                        'new_value'         => $newVal,
                        'changed_at'        => now(),
                    ]);
                }

                if (! empty($changes)) {
                    Log::info('UpdateFlightCurrentStateJob: detected changes', [
                        'canonical_key'      => $existing->canonical_key,
                        'flight_instance_id' => $instance->id,
                        'fields'             => array_keys($changes),
                    ]);
                }
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two workers raced to insert the same row. The queue retries; on retry the
            // lockForUpdate path finds the row the winning worker created.
            //
            // constraint_name distinguishes the two possible sources of the race:
            //   fi_identity_unique  — race on FlightInstance creation
            //   fc_instance_unique  — race on FlightCurrent creation
            // Both are expected to be rare; a sustained rate indicates queue concurrency
            // above the level that lockForUpdate can fully serialize.
            $msg             = $e->getMessage();
            $constraintName  = match (true) {
                str_contains($msg, 'fi_identity_unique')  => 'fi_identity_unique',
                str_contains($msg, 'fc_instance_unique')  => 'fc_instance_unique',
                // SQLite names the constraint by table/columns, not by constraint name
                str_contains($msg, 'flight_instances')    => 'fi_identity_unique',
                str_contains($msg, 'flights_current')     => 'fc_instance_unique',
                default                                   => 'unknown',
            };

            Log::warning('UpdateFlightCurrentStateJob: unique_constraint_race', [
                'event'          => 'unique_constraint_race',
                'constraint'     => $constraintName,
                'canonical_key'  => $this->canonicalKey,
                'airport_id'     => $this->airportId,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve or create the stable FlightInstance for this observation.
     *
     * The 4-tuple (airport_id, board_type, flight_number, service_date_local) is the
     * canonical identity. firstOrCreate with lockForUpdate inside a transaction is safe
     * against concurrent inserts — a UniqueConstraintViolationException triggers a retry.
     *
     * Metadata fields (airline_iata, origin_iata, destination_iata, first_seen_at) are
     * only written on creation; they are intentionally NOT updated on subsequent scrapes
     * to keep the identity record stable.
     */
    private function resolveOrCreateInstance(array $payload): FlightInstance
    {
        $flightNumber    = $payload['flight_number'] ?? '';
        $boardType       = $payload['board_type'] ?? '';
        $serviceDateLocal = $payload['service_date_local'] ?? null;

        // Lock any existing row before we decide to insert, preventing duplicate inserts
        // from concurrent workers processing the same flight.
        $existing = FlightInstance::where('airport_id', $this->airportId)
            ->where('board_type', $boardType)
            ->where('flight_number', $flightNumber)
            ->where('service_date_local', $serviceDateLocal)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return FlightInstance::create([
            'airport_id'         => $this->airportId,
            'board_type'         => $boardType,
            'flight_number'      => $flightNumber,
            'service_date_local' => $serviceDateLocal,
            'airline_iata'       => $payload['airline_iata'] ?? null,
            'origin_iata'        => $payload['origin_iata'] ?? null,
            'destination_iata'   => $payload['destination_iata'] ?? null,
            'first_seen_at'      => now(),
        ]);
    }

    private function buildAttributes(array $payload): array
    {
        return [
            'board_type'                   => $payload['board_type'] ?? null,
            'flight_number'                => $payload['flight_number'] ?? '',
            'operating_flight_number'      => $payload['operating_flight_number'] ?? null,
            'airline_iata'                 => $payload['airline_iata'] ?? null,
            'airline_name'                 => $payload['airline_name'] ?? null,
            'origin_iata'                  => $payload['origin_iata'] ?? null,
            'destination_iata'             => $payload['destination_iata'] ?? null,
            'service_date_local'           => $payload['service_date_local'] ?? null,
            'scheduled_departure_at_utc'   => $payload['scheduled_departure_at_utc'] ?? null,
            'estimated_departure_at_utc'   => $payload['estimated_departure_at_utc'] ?? null,
            'actual_departure_at_utc'      => $payload['actual_departure_at_utc'] ?? null,
            'scheduled_arrival_at_utc'     => $payload['scheduled_arrival_at_utc'] ?? null,
            'estimated_arrival_at_utc'     => $payload['estimated_arrival_at_utc'] ?? null,
            'actual_arrival_at_utc'        => $payload['actual_arrival_at_utc'] ?? null,
            'departure_terminal'           => $payload['departure_terminal'] ?? null,
            'arrival_terminal'             => $payload['arrival_terminal'] ?? null,
            'departure_gate'               => $payload['departure_gate'] ?? null,
            'arrival_gate'                 => $payload['arrival_gate'] ?? null,
            'baggage_belt'                 => $payload['baggage_belt'] ?? null,
            'status_raw'                   => $payload['status_raw'] ?? null,
            'status_normalized'            => $payload['status_normalized'] ?? null,
        ];
    }

    private function detectChanges(FlightCurrent $existing, array $payload): array
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $oldVal = $existing->getAttribute($field);
            $newVal = $payload[$field] ?? null;

            $oldStr = $oldVal instanceof \DateTimeInterface
                ? $oldVal->format('Y-m-d H:i:s')
                : (string) ($oldVal ?? '');
            $newStr = (string) ($newVal ?? '');

            if ($oldStr !== $newStr) {
                $changes[$field] = [$oldVal, $newVal];
            }
        }

        return $changes;
    }

    private function resolveDelayMinutes(array $payload): ?int
    {
        $boardType = $payload['board_type'] ?? 'departures';

        if ($boardType === 'arrivals') {
            $scheduled = $payload['scheduled_arrival_at_utc'] ?? null;
            $estimated = $payload['estimated_arrival_at_utc']
                ?? $payload['actual_arrival_at_utc']
                ?? null;
        } else {
            $scheduled = $payload['scheduled_departure_at_utc'] ?? null;
            $estimated = $payload['estimated_departure_at_utc']
                ?? $payload['actual_departure_at_utc']
                ?? null;
        }

        if (empty($scheduled) || empty($estimated)) {
            return null;
        }

        try {
            $diff = (new \DateTimeImmutable($estimated))->getTimestamp()
                  - (new \DateTimeImmutable($scheduled))->getTimestamp();
            return (int) round($diff / 60);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIsCompleted(array $payload): bool
    {
        $boardType = $payload['board_type'] ?? '';

        if ($boardType === 'departures') {
            if (! empty($payload['actual_departure_at_utc'])) {
                return true;
            }
            $status = strtolower($payload['status_normalized'] ?? '');
            return in_array($status, self::DEPARTED_STATUSES, true);
        }

        if ($boardType === 'arrivals') {
            if (! empty($payload['actual_arrival_at_utc'])) {
                return true;
            }
            $status = strtolower($payload['status_normalized'] ?? '');
            return in_array($status, self::ARRIVED_STATUSES, true);
        }

        return false;
    }
}
