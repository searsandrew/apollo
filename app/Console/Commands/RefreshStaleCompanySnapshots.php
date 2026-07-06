<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCompanySnapshotSummary;
use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('company-snapshots:refresh-stale {--limit=10} {--meta-days=1} {--transaction-days=7}')]
#[Description('Dispatch jobs for stale company snapshot data')]
class RefreshStaleCompanySnapshots extends Command
{
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $metaDays = max(1, (int) $this->option('meta-days'));
        $transactionDays = max(1, (int) $this->option('transaction-days'));
        $dispatched = 0;

        CompanySnapshot::query()
            ->where(function ($query) use ($metaDays, $transactionDays): void {
                $query->whereNull('meta_synced_at')
                    ->orWhere('meta_synced_at', '<', now()->subDays($metaDays))
                    ->orWhereNull('transactions_synced_at')
                    ->orWhere('transactions_synced_at', '<', now()->subDays($transactionDays))
                    ->orWhereNull('summary_synced_at')
                    ->orWhereColumn('summary_synced_at', '<', 'transactions_synced_at');
            })
            ->whereNotIn('status', [
                CompanySnapshot::STATUS_SYNCING_META,
                CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
            ])
            ->orderByRaw('COALESCE(transactions_synced_at, meta_synced_at, created_at) ASC')
            ->limit($limit)
            ->get()
            ->each(function (CompanySnapshot $snapshot) use (&$dispatched, $metaDays, $transactionDays): void {
                if ($snapshot->isMetaStale($metaDays)) {
                    SyncCompanySnapshotMeta::dispatch($snapshot->id);
                    $dispatched++;

                    return;
                }

                if ($snapshot->areTransactionsStale($transactionDays)) {
                    SyncCompanySnapshotTransactions::dispatch($snapshot->id);
                    $dispatched++;

                    return;
                }

                RefreshCompanySnapshotSummary::dispatch($snapshot->id);
                $dispatched++;
            });

        $this->info('Dispatched '.$dispatched.' company snapshot refresh job(s).');

        return self::SUCCESS;
    }
}
