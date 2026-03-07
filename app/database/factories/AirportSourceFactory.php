<?php

namespace Database\Factories;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class AirportSourceFactory extends Factory
{
    protected $model = AirportSource::class;

    public function definition(): array
    {
        return [
            'airport_id' => Airport::factory(),
            'board_type' => $this->faker->randomElement(['departures', 'arrivals']),
            'source_type' => $this->faker->randomElement(['json_endpoint', 'html_table', 'playwright_table']),
            'url' => $this->faker->url(),
            'scrape_interval_minutes' => $this->faker->randomElement([5, 10, 15, 30]),
            'is_active' => true,
        ];
    }
}
