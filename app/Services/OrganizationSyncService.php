<?php

namespace App\Services;

use App\Exceptions\YandexSourceChangedException;
use App\Models\Organization;
use App\Models\SyncRun;
use App\Services\YandexMaps\YandexMapsParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OrganizationSyncService
{
    public function __construct(
        private readonly YandexMapsParser $parser,
    ) {}

    public function synchronize(Organization $organization, SyncRun $run): void
    {
        $startedAt = now();

        $run->forceFill([
            'status' => 'processing',
            'error' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
            'progress' => 2,
        ])->save();

        $organization->forceFill([
            'sync_status' => 'processing',
            'sync_progress' => 2,
            'sync_started_at' => $startedAt,
            'sync_finished_at' => null,
            'last_error' => null,
        ])->save();

        try {
            $result = $this->parser->parse(
                $organization->canonical_url,
                function (int $processed, ?int $total) use ($organization, $run): void {
                    $progress = $total !== null && $total > 0
                        ? min(96, max(5, (int) floor(($processed / $total) * 92)))
                        : min(96, 5 + $processed);

                    $run->forceFill([
                        'processed_reviews' => $processed,
                        'total_reviews' => $total,
                        'progress' => $progress,
                    ])->save();

                    $organization->forceFill([
                        'sync_progress' => $progress,
                    ])->save();
                },
            );

            $organizationData = $result['organization'] ?? [];
            $reviews = array_values($result['reviews'] ?? []);

            if (
                $reviews === []
                && (int) ($organizationData['reviews_count'] ?? 0) > 0
            ) {
                throw new YandexSourceChangedException(
                    'Карточка сообщает о наличии отзывов, но сами отзывы не распознаны. Синхронизация остановлена, чтобы не заменить сохранённые данные пустым результатом.',
                );
            }

            DB::transaction(function () use ($organization, $run, $organizationData, $reviews): void {
                $now = now();
                $organization->reviews()
                    ->where('is_current', true)
                    ->update([
                        'is_current' => false,
                        'last_seen_at' => $now,
                    ]);

                foreach ($reviews as $review) {
                    $organization->reviews()->updateOrCreate(
                        ['external_id' => $review['external_id']],
                        [
                            'author' => $review['author'],
                            'author_avatar_url' => $review['author_avatar_url'] ?? null,
                            'published_at' => $review['published_at'] ?? null,
                            'body' => $review['body'],
                            'rating' => $review['rating'],
                            'source_url' => $review['source_url'] ?? $organization->canonical_url,
                            'content_hash' => $review['content_hash'],
                            'is_current' => true,
                            'last_seen_at' => $now,
                        ],
                    );
                }

                $reportedReviews = (int) ($organizationData['reviews_count'] ?? count($reviews));
                $organization->forceFill([
                    'external_id' => $organizationData['external_id'] ?: $organization->external_id,
                    'name' => $organizationData['name'] ?: $organization->name,
                    'address' => $organizationData['address'] ?: $organization->address,
                    'rating' => $organizationData['rating'] ?? $organization->rating,
                    'ratings_count' => (int) ($organizationData['ratings_count'] ?? 0),
                    'reviews_count' => $reportedReviews,
                    'source_hash' => $organizationData['source_hash'] ?? null,
                    'sync_status' => 'completed',
                    'sync_progress' => 100,
                    'sync_finished_at' => $now,
                    'last_synced_at' => $now,
                    'last_error' => null,
                ])->save();

                $run->forceFill([
                    'status' => 'completed',
                    'total_reviews' => $reportedReviews,
                    'processed_reviews' => count($reviews),
                    'progress' => 100,
                    'error' => null,
                    'finished_at' => $now,
                ])->save();

                $organization->snapshots()->create([
                    'sync_run_id' => $run->id,
                    'name' => $organization->name,
                    'address' => $organization->address,
                    'rating' => $organization->rating,
                    'ratings_count' => $organization->ratings_count,
                    'reviews_count' => $organization->reviews_count,
                    'payload' => [
                        'source_url' => $organization->canonical_url,
                        'source_hash' => $organization->source_hash,
                        'processed_reviews' => count($reviews),
                        'reviews' => array_map(
                            static fn (array $review): array => [
                                'external_id' => $review['external_id'],
                                'content_hash' => $review['content_hash'],
                                'rating' => $review['rating'],
                                'published_at' => $review['published_at'] ?? null,
                            ],
                            $reviews,
                        ),
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            $message = Str::limit(
                $exception->getMessage() ?: 'Неизвестная ошибка синхронизации.',
                2000,
            );

            $run->forceFill([
                'status' => 'failed',
                'error' => $message,
                'finished_at' => now(),
            ])->saveQuietly();

            $organization->forceFill([
                'sync_status' => 'failed',
                'sync_finished_at' => now(),
                'last_error' => $message,
            ])->saveQuietly();

            Log::error('Yandex organization sync failed.', [
                'organization_id' => $organization->id,
                'sync_run_id' => $run->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
