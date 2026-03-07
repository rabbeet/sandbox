<?php

namespace App\Domain\Flights\Models;

use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_current_id',
        'scrape_job_id',
        'field_name',
        'old_value',
        'new_value',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function flightCurrent(): BelongsTo
    {
        return $this->belongsTo(FlightCurrent::class, 'flight_current_id');
    }

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }
}
