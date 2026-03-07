<?php

namespace App\Domain\Flights\ValueObjects;

class CanonicalKey
{
    /**
     * Build canonical flight key:
     * {airport_iata}:{board_type}:{service_date_local}:{flight_number}:{scheduled_time_rounded}
     *
     * Fallbacks per spec:
     * - operating number missing → use marketing number
     * - scheduled time missing → use destination/origin + row hash
     */
    public static function build(
        string $airportIata,
        string $boardType,
        string $serviceDateLocal,
        array $row,
    ): string {
        $flightNumber = self::resolveFlightNumber($row);
        $timePart     = self::resolveTimePart($boardType, $row);

        return strtoupper($airportIata)
            . ':' . $boardType
            . ':' . $serviceDateLocal
            . ':' . strtoupper($flightNumber)
            . ':' . $timePart;
    }

    private static function resolveFlightNumber(array $row): string
    {
        $operating = $row['operating_flight_number'] ?? null;
        $marketing = $row['flight_number'] ?? $row['marketing_flight_number'] ?? null;

        $number = $operating ?: $marketing;

        if (empty($number)) {
            // Last resort: hash identifiable columns
            return 'UNKNOWN_' . substr(md5(serialize($row)), 0, 8);
        }

        return preg_replace('/\s+/', '', $number);
    }

    private static function resolveTimePart(string $boardType, array $row): string
    {
        $timeField = $boardType === 'arrivals'
            ? ($row['scheduled_arrival_at_local'] ?? $row['scheduled_arrival_at_utc'] ?? null)
            : ($row['scheduled_departure_at_local'] ?? $row['scheduled_departure_at_utc'] ?? null);

        if (! empty($timeField)) {
            try {
                // Round to nearest 5 minutes to tolerate minor timezone drifts
                $dt      = new \DateTimeImmutable($timeField);
                $minutes = (int) $dt->format('i');
                $rounded = (int) round($minutes / 5) * 5;
                return $dt->format('H') . str_pad((string) $rounded, 2, '0', STR_PAD_LEFT);
            } catch (\Throwable) {
                // Fall through to fallback
            }
        }

        // Fallback: destination/origin + row hash
        $location = $boardType === 'arrivals'
            ? ($row['origin_iata'] ?? '')
            : ($row['destination_iata'] ?? '');

        return 'X' . strtoupper($location) . substr(md5(serialize($row)), 0, 6);
    }
}
