<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'external_id',
        'author',
        'author_avatar_url',
        'published_at',
        'body',
        'rating',
        'source_url',
        'content_hash',
        'is_current',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'rating' => 'integer',
            'is_current' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
