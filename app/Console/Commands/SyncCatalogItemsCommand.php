<?php

namespace App\Console\Commands;

use App\Jobs\SyncCatalogItems;
use App\Models\CatalogSyncRun;
use App\Services\Catalog\CatalogSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('catalog:sync-items {--since=} {--limit=1000} {--pages=} {--full : Run a full catalog sync} {--now : Run synchronously instead of dispatching a queue job}')]
#[Description('Sync local catalog items from the upstream datasource')]
class SyncCatalogItemsCommand extends Command
{
    public function handle(CatalogSyncer $syncer): int
    {
        $type = $this->option('full') ? CatalogSyncRun::TYPE_FULL : CatalogSyncRun::TYPE_INCREMENTAL;
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'))->toDateTimeString() : null;
        $limit = max(1, (int) $this->option('limit'));
        $pages = $this->option('pages') === null ? null : max(1, (int) $this->option('pages'));

        if ($this->option('now')) {
            $run = $syncer->syncFromNetSuite(
                modifiedSince: $since === null ? null : Carbon::parse($since),
                limit: $limit,
                maxPages: $pages,
                type: $type,
            );

            $this->info('Catalog sync '.$run->uuid.' finished with '.$run->items_upserted.' item(s).');

            return self::SUCCESS;
        }

        SyncCatalogItems::dispatch(
            modifiedSince: $since,
            limit: $limit,
            maxPages: $pages,
            type: $type,
        );

        $this->info('Dispatched catalog item sync job.');

        return self::SUCCESS;
    }
}
