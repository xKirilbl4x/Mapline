<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SyncRun;
use App\Services\OrganizationSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SyncOrganizationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public array $backoff = [10, 30, 120];

    public function __construct(
        public readonly int $organizationId,
        public readonly int $syncRunId,
    ) {}

    public function handle(OrganizationSyncService $syncService): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);
        $run = SyncRun::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($this->syncRunId);

        if ($run->status === 'completed') {
            return;
        }

        $syncService->synchronize($organization, $run);
    }

    public function failed(Throwable $exception): void
    {
        $message = Str::limit(
            $exception->getMessage() ?: 'Неизвестная ошибка фоновой задачи.',
            2000,
        );

        SyncRun::query()
            ->whereKey($this->syncRunId)
            ->update([
                'status' => 'failed',
                'error' => $message,
                'finished_at' => now(),
            ]);

        Organization::query()
            ->whereKey($this->organizationId)
            ->update([
                'sync_status' => 'failed',
                'sync_finished_at' => now(),
                'last_error' => $message,
            ]);
    }
}
