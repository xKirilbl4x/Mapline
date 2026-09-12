<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'status',
        'total_reviews',
        'processed_reviews',
        'progress',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'total_reviews' => 'integer',
            'processed_reviews' => 'integer',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
