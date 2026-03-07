<?php

namespace App\Http\Requests\Airports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAirportSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('airport-sources.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['sometimes', Rule::in(['json_endpoint', 'html_table', 'playwright_table', 'playwright_cards', 'custom_playwright'])],
            'url' => ['sometimes', 'url', 'max:2048'],
            'scrape_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'is_active' => ['boolean'],
        ];
    }
}
