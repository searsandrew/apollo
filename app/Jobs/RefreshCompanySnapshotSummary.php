<?php

namespace App\Jobs;

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSummaryCompiler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshCompanySnapshotSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $companySnapshotId,
    ) {}

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('company-snapshot-summary:'.$this->companySnapshotId))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    public function handle(CompanySnapshotSummaryCompiler $compiler): void
    {
        $snapshot = CompanySnapshot::query()->findOrFail($this->companySnapshotId);

        $compiler->compile($snapshot);
    }
}
