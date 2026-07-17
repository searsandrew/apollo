<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('company-snapshots:refresh-full-transactions {--limit=100}')]
#[Description('Dispatch full company snapshot transaction refresh jobs for reconciliation')]
class RefreshFullCompanySnapshotTransactions extends Command
{
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        CompanySnapshot::query()
            ->where('status', CompanySnapshot::STATUS_ACTIVE)
            ->orderByRaw('COALESCE(transactions_synced_at, meta_synced_at, created_at) ASC')
            ->limit($limit)
            ->get()
            ->each(function (CompanySnapshot $snapshot) use (&$dispatched): void {
                SyncCompanySnapshotTransactions::dispatch($snapshot->id, full: true);
                $dispatched++;
            });

        $this->info('Dispatched '.$dispatched.' full company snapshot transaction refresh job(s).');

        return self::SUCCESS;
    }
}
