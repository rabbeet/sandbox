<?php

namespace App\Domain\Scraping\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeArtifact extends Model
{
    use HasFactory;

    protected $fillable = [
        'scrape_job_id',
        'artifact_type',
        'storage_path',
        'size_bytes',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }
}
