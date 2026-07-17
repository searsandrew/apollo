<?php

namespace App\Jobs;

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncCompanySnapshotTransactions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $companySnapshotId,
        public bool $full = false,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('company-snapshot:'.$this->companySnapshotId))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }

    public function handle(CompanySnapshotSyncer $syncer): void
    {
        $snapshot = CompanySnapshot::query()->findOrFail($this->companySnapshotId);

        $syncer->syncTransactions($snapshot, full: $this->full);

        RefreshCompanySnapshotSummary::dispatch($snapshot->id);
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
