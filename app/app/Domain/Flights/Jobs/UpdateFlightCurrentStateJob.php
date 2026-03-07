<?php

namespace App\Domain\Flights\Jobs;

use App\Domain\Flights\Models\FlightChange;
use App\Domain\Flights\Models\FlightCurrent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        public readonly string $canonicalKey,
        public readonly array $normalizedPayload,
    ) {}

    public function handle(): void
    {
        $payload = $this->normalizedPayload;

        $isCompleted = $this->resolveIsCompleted($payload);
        $delayMinutes = $this->resolveDelayMinutes($payload);

        $existing = FlightCurrent::where('canonical_key', $this->canonicalKey)->first();

        if (! $existing) {
            FlightCurrent::create([
                ...$this->buildAttributes($payload),
                'airport_id'      => $this->airportId,
                'canonical_key'   => $this->canonicalKey,
                'delay_minutes'   => $delayMinutes,
                'is_active'       => true,
                'is_completed'    => $isCompleted,
                'last_seen_at'    => now(),
                'last_changed_at' => now(),
            ]);

            Log::info('UpdateFlightCurrentStateJob: created new flight', [
                'canonical_key' => $this->canonicalKey,
            ]);
            return;
        }

        // Detect changes in tracked fields
        $changes = $this->detectChanges($existing, $payload);

        $updates = [
            ...$this->buildAttributes($payload),
            'delay_minutes' => $delayMinutes,
            'is_completed'  => $isCompleted,
            'last_seen_at'  => now(),
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
                'canonical_key' => $this->canonicalKey,
                'fields'        => array_keys($changes),
            ]);
        }
    }

    private function buildAttributes(array $payload): array
    {
        return [
            'board_type'                   => $payload['board_type'] ?? null,
            'flight_number'                => $payload['flight_number'] ?? '',
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

            // Normalize to string for comparison (handles Carbon vs string)
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
        $scheduled  = $payload['scheduled_departure_at_utc'] ?? null;
        $estimated  = $payload['estimated_departure_at_utc'] ?? $payload['actual_departure_at_utc'] ?? null;

        if (empty($scheduled) || empty($estimated)) {
            return null;
        }

        try {
            $scheduledDt = new \DateTimeImmutable($scheduled);
            $estimatedDt = new \DateTimeImmutable($estimated);
            $diff        = $estimatedDt->getTimestamp() - $scheduledDt->getTimestamp();
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
