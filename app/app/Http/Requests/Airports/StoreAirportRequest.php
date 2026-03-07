<?php

namespace App\Http\Requests\Airports;

use Illuminate\Foundation\Http\FormRequest;

class StoreAirportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('airports.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'iata' => ['required', 'string', 'size:3', 'unique:airports,iata', 'uppercase'],
            'icao' => ['nullable', 'string', 'size:4', 'unique:airports,icao', 'uppercase'],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2', 'uppercase'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'is_active' => ['boolean'],
        ];
    }
}
