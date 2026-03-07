<?php

namespace App\Http\Requests\Airports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAirportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('airports.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'iata' => ['sometimes', 'string', 'size:3', Rule::unique('airports', 'iata')->ignore($this->route('airport')), 'uppercase'],
            'icao' => ['nullable', 'string', 'size:4', Rule::unique('airports', 'icao')->ignore($this->route('airport')), 'uppercase'],
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2', 'uppercase'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'is_active' => ['boolean'],
        ];
    }
}
