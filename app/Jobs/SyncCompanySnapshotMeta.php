<?php

namespace App\Jobs;

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncCompanySnapshotMeta implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $companySnapshotId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('company-snapshot:'.$this->companySnapshotId))
                ->releaseAfter(60)
                ->expireAfter(1800),
        ];
    }

    public function handle(CompanySnapshotSyncer $syncer): void
    {
        $snapshot = CompanySnapshot::query()->findOrFail($this->companySnapshotId);

        $syncer->syncMeta($snapshot);

        SyncCompanySnapshotTransactions::dispatch($snapshot->id);
    }

    public function failed(Throwable $exception): void
    {
        CompanySnapshot::query()
            ->whereKey($this->companySnapshotId)
            ->update([
                'status' => CompanySnapshot::STATUS_FAILED,
                'sync_finished_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);
    }
}
