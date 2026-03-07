<?php

namespace App\Domain\Airports\Models;

use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AirportSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_id',
        'board_type',
        'source_type',
        'url',
        'active_parser_version_id',
        'scrape_interval_minutes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'scrape_interval_minutes' => 'integer',
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function activeParserVersion(): BelongsTo
    {
        return $this->belongsTo(ParserVersion::class, 'active_parser_version_id');
    }

    public function parserVersions(): HasMany
    {
        return $this->hasMany(ParserVersion::class);
    }

    public function scrapeJobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class);
    }
}
