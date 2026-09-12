<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'sync_run_id',
        'name',
        'address',
        'rating',
        'ratings_count',
        'reviews_count',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'payload' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
