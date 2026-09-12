<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_url',
        'canonical_url',
        'external_id',
        'name',
        'address',
        'rating',
        'ratings_count',
        'reviews_count',
        'sync_status',
        'sync_progress',
        'sync_started_at',
        'sync_finished_at',
        'last_synced_at',
        'last_error',
        'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'sync_progress' => 'integer',
            'sync_started_at' => 'datetime',
            'sync_finished_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }

    public function latestSyncRun(): ?SyncRun
    {
        return $this->syncRuns()->latest('id')->first();
    }
}
