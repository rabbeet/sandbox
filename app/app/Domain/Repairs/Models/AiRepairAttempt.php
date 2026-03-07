<?php

namespace App\Domain\Repairs\Models;

use App\Domain\Airports\Models\ParserVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRepairAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'parser_failure_id',
        'candidate_parser_version_id',
        'status',
        'score',
        'score_details',
        'canary_runs',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'score_details' => 'array',
        'score' => 'float',
        'completed_at' => 'datetime',
    ];

    public function parserFailure(): BelongsTo
    {
        return $this->belongsTo(ParserFailure::class);
    }

    public function candidateParserVersion(): BelongsTo
    {
        return $this->belongsTo(ParserVersion::class, 'candidate_parser_version_id');
    }
}
