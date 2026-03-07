<?php

namespace App\Domain\Repairs\Models;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParserFailure extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_source_id',
        'parser_version_id',
        'scrape_job_id',
        'failure_type',
        'severity',
        'error_code',
        'error_message',
        'failure_details',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'failure_details' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function airportSource(): BelongsTo
    {
        return $this->belongsTo(AirportSource::class);
    }

    public function parserVersion(): BelongsTo
    {
        return $this->belongsTo(ParserVersion::class);
    }

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }

    public function repairAttempts(): HasMany
    {
        return $this->hasMany(AiRepairAttempt::class, 'parser_failure_id');
    }
}
