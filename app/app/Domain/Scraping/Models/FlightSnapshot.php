<?php

namespace App\Domain\Scraping\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'scrape_job_id',
        'canonical_key',
        'raw_payload',
        'normalized_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
    ];

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }
}
