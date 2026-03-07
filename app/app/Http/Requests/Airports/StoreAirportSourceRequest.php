<?php

namespace App\Http\Requests\Airports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAirportSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('airport-sources.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'board_type' => ['required', Rule::in(['departures', 'arrivals'])],
            'source_type' => ['required', Rule::in(['json_endpoint', 'html_table', 'playwright_table', 'playwright_cards', 'custom_playwright'])],
            'url' => ['required', 'url', 'max:2048'],
            'scrape_interval_minutes' => ['integer', 'min:1', 'max:1440'],
            'is_active' => ['boolean'],
        ];
    }
}
