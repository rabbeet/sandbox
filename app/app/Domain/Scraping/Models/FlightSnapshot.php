<?php

namespace App\Domain\Scraping\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FlightSnapshot is an append-only observation record.
 *
 * Once created, raw_payload and normalized_payload must never be modified —
 * they represent what the scraper actually saw at a point in time.
 * The only permitted post-creation write is setting flight_instance_id after
 * the stable identity is resolved by UpdateFlightCurrentStateJob.
 */
class FlightSnapshot extends Model
{
    use HasFactory;

    /**
     * Fields that may be set on creation.
     * flight_instance_id is included here so UpdateFlightCurrentStateJob
     * can back-fill it via ::where()->update(), which bypasses this guard.
     */
    protected $fillable = [
        'scrape_job_id',
        'flight_instance_id',
        'canonical_key',
        'raw_payload',
        'normalized_payload',
    ];

    /**
     * Fields that are immutable after the row is first persisted.
     * Any attempt to change these via update() or save() on an existing
     * model will throw a LogicException.
     */
    private const IMMUTABLE_FIELDS = [
        'scrape_job_id',
        'canonical_key',
        'raw_payload',
        'normalized_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
    ];

    /**
     * Guard immutable fields on any model-level save after initial creation.
     *
     * Note: bulk ::where()->update() calls bypass Eloquent model events
     * entirely — this guard is intentionally NOT applied there so that the
     * flight_instance_id back-fill in UpdateFlightCurrentStateJob can run
     * without model instantiation overhead.
     */
    protected static function booted(): void
    {
        static::updating(function (self $snapshot) {
            $dirty = array_keys($snapshot->getDirty());
            $violations = array_intersect($dirty, self::IMMUTABLE_FIELDS);

            if (! empty($violations)) {
                throw new \LogicException(
                    'FlightSnapshot is append-only. Cannot modify: ' . implode(', ', $violations)
                );
            }
        });
    }

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }

    public function flightInstance(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Flights\Models\FlightInstance::class);
    }
}
