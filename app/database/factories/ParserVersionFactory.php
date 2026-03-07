<?php

namespace Database\Factories;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParserVersionFactory extends Factory
{
    protected $model = ParserVersion::class;

    public function definition(): array
    {
        return [
            'airport_source_id' => AirportSource::factory(),
            'version' => 1,
            'definition' => [
                'mode' => 'json_endpoint',
                'url' => $this->faker->url(),
                'fields' => [
                    'flight_number' => 'flightNumber',
                    'status_raw' => 'status',
                    'scheduled_departure_at_utc' => 'scheduledDeparture',
                ],
            ],
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true, 'activated_at' => now()]);
    }
}
