<?php

namespace App\Http\Controllers;

use App\Jobs\SyncOrganizationJob;
use App\Models\Organization;
use App\Models\Review;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\YandexMaps\YandexUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrganizationController extends Controller
{
    private const STALE_SYNC_MINUTES = 10;

    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return response()->json([
                'organization' => null,
                'sync' => null,
                'reviews' => [],
                'pagination' => $this->emptyPagination(),
            ]);
        }

        $this->failStaleSync($organization);
        $organization->refresh();

        return response()->json($this->payload($organization, true));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $canonicalUrl = YandexUrl::normalize($validated['source_url']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'source_url' => [$exception->getMessage()],
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        [$organization, $run] = DB::transaction(function () use ($user, $validated, $canonicalUrl): array {
            $organization = Organization::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($organization) {
                $this->failStaleSync($organization);
                $organization->refresh();
            }

            if (
                $organization
                && in_array($organization->sync_status, ['queued', 'processing'], true)
            ) {
                abort(409, 'Синхронизация этой карточки уже выполняется.');
            }

            $isNewSource = ! $organization
                || $organization->canonical_url !== $canonicalUrl;

            $organization ??= new Organization(['user_id' => $user->id]);
            $organization->source_url = $validated['source_url'];
            $organization->canonical_url = $canonicalUrl;
            $organization->sync_status = 'queued';
            $organization->sync_progress = 0;
            $organization->sync_started_at = null;
            $organization->sync_finished_at = null;
            $organization->last_error = null;

            if ($isNewSource) {
                $organization->external_id = null;
                $organization->name = null;
                $organization->address = null;
                $organization->rating = null;
                $organization->ratings_count = 0;
                $organization->reviews_count = 0;
                $organization->source_hash = null;
            }

            $organization->save();

            $run = $organization->syncRuns()->create([
                'status' => 'queued',
                'total_reviews' => null,
                'processed_reviews' => 0,
                'progress' => 0,
            ]);

            return [$organization, $run];
        });

        SyncOrganizationJob::dispatch($organization->id, $run->id);

        $organization->refresh();
        $run->refresh();

        return response()->json([
            ...$this->payload($organization, true, $run),
            'message' => $run->status === 'completed'
                ? 'Данные организации обновлены.'
                : 'Карточка поставлена в очередь на синхронизацию.',
        ], $run->status === 'completed' ? 200 : 202);
    }

    public function reviews(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return response()->json([
                'reviews' => [],
                'pagination' => $this->emptyPagination(),
            ]);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $paginator = $this->reviewQuery($organization)->paginate(50, ['*'], 'page', $page);

        return response()->json($this->reviewPayload($paginator));
    }

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return response()->json([
                'organization' => null,
                'sync' => null,
            ]);
        }

        $this->failStaleSync($organization);
        $organization->refresh();

        return response()->json($this->payload($organization, false));
    }

    private function failStaleSync(Organization $organization): void
    {
        if (! in_array($organization->sync_status, ['queued', 'processing'], true)) {
            return;
        }

        $run = $organization->latestSyncRun();

        if (! $run || ! in_array($run->status, ['queued', 'processing'], true)) {
            return;
        }

        $startedAt = $run->started_at ?? $run->created_at;

        if (! $startedAt || $startedAt->greaterThan(now()->subMinutes(self::STALE_SYNC_MINUTES))) {
            return;
        }

        $message = 'Синхронизация остановлена: обработчик очереди не ответил вовремя.';

        $run->forceFill([
            'status' => 'failed',
            'error' => $message,
            'finished_at' => now(),
        ])->save();

        $organization->forceFill([
            'sync_status' => 'failed',
            'sync_finished_at' => now(),
            'last_error' => $message,
        ])->save();
    }

    private function payload(
        Organization $organization,
        bool $withReviews,
        ?SyncRun $sync = null,
    ): array {
        $sync ??= $organization->latestSyncRun();
        $payload = [
            'organization' => $this->organizationPayload($organization),
            'sync' => $sync ? $this->syncPayload($sync) : null,
        ];

        if ($withReviews) {
            $page = request()->integer('page', 1);
            $paginator = $this->reviewQuery($organization)->paginate(
                50,
                ['*'],
                'page',
                max(1, (int) $page),
            );
            $payload += $this->reviewPayload($paginator);
        }

        return $payload;
    }

    private function reviewQuery(Organization $organization): HasMany
    {
        return $organization->reviews()
            ->where('is_current', true)
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    private function reviewPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'reviews' => array_values(array_map(
                fn (Review $review): array => $this->reviewPayloadItem($review),
                $paginator->items(),
            )),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'source_url' => $organization->source_url,
            'canonical_url' => $organization->canonical_url,
            'external_id' => $organization->external_id,
            'name' => $organization->name,
            'address' => $organization->address,
            'rating' => $organization->rating,
            'ratings_count' => $organization->ratings_count,
            'reviews_count' => $organization->reviews_count,
            'sync_status' => $organization->sync_status,
            'sync_progress' => $organization->sync_progress,
            'last_error' => $organization->last_error,
            'last_synced_at' => $organization->last_synced_at?->toIso8601String(),
        ];
    }

    private function syncPayload(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'total_reviews' => $run->total_reviews,
            'processed_reviews' => $run->processed_reviews,
            'progress' => $run->progress,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function reviewPayloadItem(Review $review): array
    {
        return [
            'id' => $review->id,
            'external_id' => $review->external_id,
            'author' => $review->author,
            'author_avatar_url' => $review->author_avatar_url,
            'published_at' => $review->published_at?->toIso8601String(),
            'body' => $review->body,
            'rating' => $review->rating,
            'source_url' => $review->source_url,
        ];
    }

    private function emptyPagination(): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 50,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }
}
