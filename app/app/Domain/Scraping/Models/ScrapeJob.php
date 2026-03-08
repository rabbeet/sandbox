<?php

namespace App\Domain\Scraping\Models;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_source_id',
        'parser_version_id',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'row_count',
        'quality_score',
        'error_code',
        'error_message',
        'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'quality_score' => 'float',
    ];

    public function airportSource(): BelongsTo
    {
        return $this->belongsTo(AirportSource::class);
    }

    public function parserVersion(): BelongsTo
    {
        return $this->belongsTo(ParserVersion::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ScrapeArtifact::class);
    }

    public function flightSnapshots(): HasMany
    {
        return $this->hasMany(FlightSnapshot::class);
    }
}
