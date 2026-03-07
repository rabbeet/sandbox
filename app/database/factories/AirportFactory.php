<?php

namespace Database\Factories;

use App\Domain\Airports\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

class AirportFactory extends Factory
{
    protected $model = Airport::class;

    public function definition(): array
    {
        return [
            'iata' => strtoupper($this->faker->unique()->lexify('???')),
            'icao' => strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->city() . ' International Airport',
            'city' => $this->faker->city(),
            'country' => strtoupper($this->faker->countryCode()),
            'timezone' => $this->faker->timezone(),
            'is_active' => true,
        ];
    }
}
