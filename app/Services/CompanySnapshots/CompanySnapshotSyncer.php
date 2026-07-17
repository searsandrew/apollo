<?php

namespace App\Services\CompanySnapshots;

use App\Jobs\RefreshCompanySnapshotSummary;
use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use App\Models\CompanySummary;
use App\Services\NetSuite\NetSuiteCompanySnapshotRepository;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class CompanySnapshotSyncer
{
    private const int INCREMENTAL_OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly CompanySnapshotDatabaseManager $databaseManager,
        private readonly NetSuiteCompanySnapshotRepository $netSuite,
    ) {}

    public function ensureSnapshot(int $netsuiteCompanyId): CompanySnapshot
    {
        $connectionName = $this->databaseManager->connectionNameFor($netsuiteCompanyId);
        $databasePath = $this->databaseManager->databasePathFor($netsuiteCompanyId);

        $snapshot = CompanySnapshot::query()->firstOrCreate(
            ['netsuite_company_id' => $netsuiteCompanyId],
            [
                'connection_name' => $connectionName,
                'database_path' => $databasePath,
                'status' => CompanySnapshot::STATUS_PENDING,
                'schema_version' => CompanySnapshot::SCHEMA_VERSION,
            ],
        );

        $snapshot->forceFill([
            'connection_name' => $snapshot->connection_name ?: $connectionName,
            'database_path' => $snapshot->database_path ?: $databasePath,
            'schema_version' => CompanySnapshot::SCHEMA_VERSION,
            'last_viewed_at' => now(),
        ])->save();

        $this->databaseManager->ensureDatabase($snapshot);

        return $snapshot->refresh();
    }

    public function queueRefreshIfStale(CompanySnapshot $snapshot, int $metaStaleDays = 1, int $transactionStaleDays = 7): void
    {
        if ($snapshot->isMetaStale($metaStaleDays)) {
            $pendingDispatch = SyncCompanySnapshotMeta::dispatch($snapshot->id);

            if (! app()->runningInConsole()) {
                $pendingDispatch->afterResponse();
            }

            return;
        }

        if ($snapshot->areTransactionsStale($transactionStaleDays)) {
            $pendingDispatch = SyncCompanySnapshotTransactions::dispatch($snapshot->id);

            if (! app()->runningInConsole()) {
                $pendingDispatch->afterResponse();
            }

            return;
        }

        if ($snapshot->summary_synced_at === null || $snapshot->summary_synced_at->lt($snapshot->transactions_synced_at)) {
            $pendingDispatch = RefreshCompanySnapshotSummary::dispatch($snapshot->id);

            if (! app()->runningInConsole()) {
                $pendingDispatch->afterResponse();
            }
        }
    }

    public function syncMeta(CompanySnapshot $snapshot): CompanySnapshot
    {
        try {
            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_SYNCING_META,
                'sync_started_at' => now(),
                'last_error' => null,
            ])->save();

            $this->databaseManager->ensureDatabase($snapshot);

            $meta = $this->netSuite->fetchMeta($snapshot->netsuite_company_id);

            if ($meta === null) {
                throw new RuntimeException('NetSuite company '.$snapshot->netsuite_company_id.' was not found.');
            }

            $this->writeMeta($snapshot, $meta);
            $this->upsertSummaryFromMeta($snapshot, $meta);

            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_ACTIVE,
                'meta_synced_at' => now(),
                'sync_finished_at' => now(),
                'last_error' => null,
            ])->save();

            return $snapshot->refresh();
        } catch (Throwable $exception) {
            $this->markFailed($snapshot, $exception);

            throw $exception;
        }
    }

    public function syncTransactions(CompanySnapshot $snapshot, bool $full = false): CompanySnapshot
    {
        try {
            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
                'sync_started_at' => now(),
                'last_error' => null,
            ])->save();

            $this->databaseManager->ensureDatabase($snapshot);

            $transactionOffset = 0;
            $transactionLineOffset = 0;
            $limit = 1000;
            $previousCursor = $this->transactionCursor($snapshot);
            $modifiedSince = $full ? null : $this->incrementalModifiedSince($previousCursor);
            $syncMode = $modifiedSince === null ? 'full' : 'incremental';
            $transactionCount = 0;
            $transactionLineCount = 0;

            do {
                $page = $this->netSuite->fetchTransactionPage($snapshot->netsuite_company_id, $limit, $transactionOffset, $modifiedSince);
                $this->writeTransactions($snapshot, $page['items']);
                $transactionCount += count($page['items']);
                $transactionOffset += $limit;
            } while ($page['has_more']);

            if ($syncMode === 'full' || $transactionCount > 0) {
                do {
                    $page = $this->netSuite->fetchTransactionLinePage($snapshot->netsuite_company_id, $limit, $transactionLineOffset, $modifiedSince);
                    $this->writeTransactionLines($snapshot, $page['items']);
                    $transactionLineCount += count($page['items']);
                    $transactionLineOffset += $limit;
                } while ($page['has_more']);
            }

            $currentCursor = $this->latestTransactionLastModifiedAt($snapshot) ?? $previousCursor;

            $this->writeSyncState($snapshot, 'transactions', [
                'mode' => $syncMode,
                'previous_cursor' => $previousCursor,
                'modified_since' => $modifiedSince,
                'fetched_count' => $transactionCount,
                'last_offset' => $transactionOffset,
            ], $currentCursor);

            $this->writeSyncState($snapshot, 'transaction_lines', [
                'mode' => $syncMode,
                'transaction_cursor' => $currentCursor,
                'transaction_modified_since' => $modifiedSince,
                'fetched_count' => $transactionLineCount,
                'last_offset' => $transactionLineOffset,
            ], $currentCursor);

            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_ACTIVE,
                'transactions_synced_at' => now(),
                'sync_finished_at' => now(),
                'last_error' => null,
            ])->save();

            return $snapshot->refresh();
        } catch (Throwable $exception) {
            $this->markFailed($snapshot, $exception);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function debugSnapshot(CompanySnapshot $snapshot): array
    {
        $connection = $this->databaseManager->ensureDatabase($snapshot);

        return [
            'snapshot' => $snapshot->fresh()?->toArray(),
            'summary' => CompanySummary::query()
                ->where('company_snapshot_id', $snapshot->id)
                ->first()
                ?->toArray(),
            'sqlite' => [
                'connection' => $snapshot->connection_name,
                'path' => $snapshot->database_path,
                'meta' => $this->readMeta($snapshot),
                'transaction_count' => $connection->table('transactions')->count(),
                'transaction_line_count' => $connection->table('transaction_lines')->count(),
                'recent_transactions' => $connection->table('transactions')
                    ->orderByDesc('trandate')
                    ->orderByDesc('netsuite_id')
                    ->limit(10)
                    ->get()
                    ->map(fn (object $transaction): array => (array) $transaction)
                    ->all(),
                'sync_state' => $connection->table('sync_state')->get()
                    ->map(fn (object $state): array => (array) $state)
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeMeta(CompanySnapshot $snapshot, array $meta): void
    {
        $connection = $this->databaseManager->connection($snapshot);

        $rows = collect($meta)
            ->map(fn (mixed $value, string $key): array => [
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        $connection->table('meta')->upsert($rows, ['key'], ['value', 'updated_at']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     */
    private function writeTransactions(CompanySnapshot $snapshot, array $transactions): void
    {
        if ($transactions === []) {
            return;
        }

        $now = now();
        $connection = $this->databaseManager->connection($snapshot);

        $rows = collect($transactions)->map(fn (array $transaction): array => [
            'netsuite_id' => $transaction['netsuite_id'],
            'tranid' => $transaction['tranid'],
            'other_ref_num' => $transaction['other_ref_num'] ?? null,
            'type' => $transaction['type'],
            'status' => $transaction['status'],
            'trandate' => $transaction['trandate'],
            'total' => $transaction['total'],
            'foreign_total' => $transaction['foreign_total'],
            'currency' => $transaction['currency'],
            'memo' => $transaction['memo'],
            'last_modified_at' => $transaction['last_modified_at'],
            'raw_payload' => json_encode($transaction['raw_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $connection->table('transactions')->upsert(
            $rows,
            ['netsuite_id'],
            ['tranid', 'other_ref_num', 'type', 'status', 'trandate', 'total', 'foreign_total', 'currency', 'memo', 'last_modified_at', 'raw_payload', 'synced_at', 'updated_at'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactionLines
     */
    private function writeTransactionLines(CompanySnapshot $snapshot, array $transactionLines): void
    {
        if ($transactionLines === []) {
            return;
        }

        $now = now();
        $connection = $this->databaseManager->connection($snapshot);

        $rows = collect($transactionLines)->map(fn (array $line): array => [
            'transaction_netsuite_id' => $line['transaction_netsuite_id'],
            'line_id' => $line['line_id'],
            'item_id' => $line['item_id'],
            'item_name' => $line['item_name'],
            'quantity' => $line['quantity'],
            'rate' => $line['rate'],
            'amount' => $line['amount'],
            'memo' => $line['memo'],
            'raw_payload' => json_encode($line['raw_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $connection->table('transaction_lines')->upsert(
            $rows,
            ['transaction_netsuite_id', 'line_id'],
            ['item_id', 'item_name', 'quantity', 'rate', 'amount', 'memo', 'raw_payload', 'synced_at', 'updated_at'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeSyncState(CompanySnapshot $snapshot, string $scope, array $payload, ?string $cursorValue = null): void
    {
        $now = now();

        $this->databaseManager->connection($snapshot)->table('sync_state')->upsert([
            [
                'scope' => $scope,
                'cursor_value' => $cursorValue,
                'synced_at' => $now,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['scope'], ['cursor_value', 'synced_at', 'payload', 'updated_at']);
    }

    private function transactionCursor(CompanySnapshot $snapshot): ?string
    {
        $storedCursor = $this->databaseManager->connection($snapshot)
            ->table('sync_state')
            ->where('scope', 'transactions')
            ->value('cursor_value');

        if (filled($storedCursor)) {
            return $this->normalizeDateTimeString($storedCursor);
        }

        return $this->latestTransactionLastModifiedAt($snapshot);
    }

    private function latestTransactionLastModifiedAt(CompanySnapshot $snapshot): ?string
    {
        return $this->databaseManager->connection($snapshot)
            ->table('transactions')
            ->whereNotNull('last_modified_at')
            ->pluck('last_modified_at')
            ->filter()
            ->map(fn (mixed $lastModifiedAt): ?string => $this->normalizeDateTimeString($lastModifiedAt))
            ->filter()
            ->max();
    }

    private function incrementalModifiedSince(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        return Carbon::parse($cursor)
            ->subMinutes(self::INCREMENTAL_OVERLAP_MINUTES)
            ->format('Y-m-d H:i:s');
    }

    private function normalizeDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function upsertSummaryFromMeta(CompanySnapshot $snapshot, array $meta): void
    {
        CompanySummary::query()->updateOrCreate(
            ['netsuite_company_id' => $snapshot->netsuite_company_id],
            [
                'company_snapshot_id' => $snapshot->id,
                'account_number' => $meta['account_number'] ?? null,
                'company_name' => $meta['company_name'] ?? null,
                'entity_id' => $meta['entity_id'] ?? null,
                'terms' => $meta['terms'] ?? null,
                'sales_rep_id' => $meta['sales_rep_id'] ?? null,
                'snapshot_synced_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(CompanySnapshot $snapshot): array
    {
        return $this->databaseManager->connection($snapshot)
            ->table('meta')
            ->get()
            ->mapWithKeys(fn (object $meta): array => [
                $meta->key => json_decode((string) $meta->value, true),
            ])
            ->all();
    }

    private function markFailed(CompanySnapshot $snapshot, Throwable $exception): void
    {
        $snapshot->forceFill([
            'status' => CompanySnapshot::STATUS_FAILED,
            'sync_finished_at' => now(),
            'last_error' => $exception->getMessage(),
        ])->save();
    }
}
