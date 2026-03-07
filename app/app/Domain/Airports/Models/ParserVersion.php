<?php

namespace App\Domain\Airports\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParserVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_source_id',
        'version',
        'definition',
        'is_active',
        'activated_at',
        'deactivated_at',
        'created_by',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function airportSource(): BelongsTo
    {
        return $this->belongsTo(AirportSource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
