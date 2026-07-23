<?php

namespace App\Jobs;

use App\Models\CatalogSyncRun;
use App\Services\Catalog\CatalogSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

class SyncCatalogItems implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public ?string $modifiedSince = null,
        public int $limit = 1000,
        public ?int $maxPages = null,
        public string $type = CatalogSyncRun::TYPE_INCREMENTAL,
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
            (new WithoutOverlapping('catalog-items'))
                ->releaseAfter(300)
                ->expireAfter(7200),
        ];
    }

    public function handle(CatalogSyncer $syncer): void
    {
        $syncer->syncFromNetSuite(
            modifiedSince: $this->modifiedSince === null ? null : Carbon::parse($this->modifiedSince),
            limit: $this->limit,
            maxPages: $this->maxPages,
            type: $this->type,
        );
    }
}
