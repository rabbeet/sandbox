<?php

namespace App\Domain\Flights\ValueObjects;

/**
 * Builds a human-readable canonical key string for a flight observation.
 *
 * Format: {airport_iata}:{board_type}:{service_date_local}:{flight_number}
 *
 * This string is used for logging, API responses, and as a convenience label
 * on FlightCurrent/FlightSnapshot records. It is NOT the primary lookup
 * mechanism — that role belongs to flight_instances with its stable 4-tuple
 * unique constraint (airport_id, board_type, flight_number, service_date_local).
 *
 * Scheduled time is intentionally excluded from the key string.
 * Rationale: scheduled_time is mutable state. Retimes change the scheduled
 * time without changing the logical flight. Including it in the identity would
 * cause a new flight record to be created on every retime.
 *
 * Identity stability rules:
 *   1. flight_number is the display (marketing) number — what the board shows.
 *      operating_flight_number is stored as metadata; it does not affect identity.
 *   2. service_date_local is the operational date, resolved by NormalizeScrapePayloadJob
 *      from (in priority order): explicit row field → local scheduled time → airport now().
 *      This is a system-level guarantee, not a parser discipline requirement.
 *   3. Mutable fields (status, gates, estimated/actual times) never appear in the key.
 */
class CanonicalKey
{
    public static function build(
        string $airportIata,
        string $boardType,
        string $serviceDateLocal,
        array $row,
    ): string {
        $flightNumber = self::resolveFlightNumber($row, $airportIata, $boardType, $serviceDateLocal);

        return strtoupper($airportIata)
            . ':' . $boardType
            . ':' . $serviceDateLocal
            . ':' . strtoupper($flightNumber);
    }

    private static function resolveFlightNumber(
        array $row,
        string $airportIata,
        string $boardType,
        string $serviceDateLocal,
    ): string {
        // Display (marketing) number takes priority — it is stable across scrapes
        // regardless of whether the parser also extracts the operating number.
        $marketing = $row['flight_number'] ?? $row['flightNumber'] ?? $row['marketing_flight_number'] ?? null;
        $operating = $row['operating_flight_number'] ?? $row['operatingFlightNumber'] ?? null;

        $number = $marketing ?: $operating;

        if (empty($number)) {
            // Fallback: hash only stable identity fields — never mutable state fields.
            $location = $boardType === 'arrivals'
                ? ($row['origin_iata'] ?? $row['originIata'] ?? '')
                : ($row['destination_iata'] ?? $row['destinationIata'] ?? '');

            $stableIdentity = implode('|', [
                strtoupper($airportIata),
                $boardType,
                $serviceDateLocal,
                strtoupper($location),
            ]);

            return 'UNKNOWN_' . substr(md5($stableIdentity), 0, 8);
        }

        return preg_replace('/\s+/', '', $number);
    }
}
