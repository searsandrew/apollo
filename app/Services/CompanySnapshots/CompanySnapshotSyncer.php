<?php

namespace App\Services\CompanySnapshots;

use App\Jobs\RefreshCompanySnapshotSummary;
use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransaction;
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

    public function queueTransactionRefresh(CompanySnapshot $snapshot, int $netsuiteTransactionId): void
    {
        $pendingDispatch = SyncCompanySnapshotTransaction::dispatch($snapshot->id, $netsuiteTransactionId);

        if (! app()->runningInConsole()) {
            $pendingDispatch->afterResponse();
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
            $transactionLinkOffset = 0;
            $transactionTrackingNumberOffset = 0;
            $limit = 1000;
            $previousCursor = $this->transactionCursor($snapshot);
            $modifiedSince = $full ? null : $this->incrementalModifiedSince($previousCursor);
            $syncMode = $modifiedSince === null ? 'full' : 'incremental';
            $transactionCount = 0;
            $transactionLineCount = 0;
            $transactionLinkCount = 0;
            $transactionTrackingNumberCount = 0;

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

            do {
                $page = $this->netSuite->fetchTransactionLinkPage($snapshot->netsuite_company_id, $limit, $transactionLinkOffset, $modifiedSince);
                $this->writeTransactionLinks($snapshot, $page['items']);
                $transactionLinkCount += count($page['items']);
                $transactionLinkOffset += $limit;
            } while ($page['has_more']);

            do {
                $page = $this->netSuite->fetchTransactionTrackingNumberPage($snapshot->netsuite_company_id, $limit, $transactionTrackingNumberOffset, $modifiedSince);
                $this->writeTransactionTrackingNumbers($snapshot, $page['items']);
                $transactionTrackingNumberCount += count($page['items']);
                $transactionTrackingNumberOffset += $limit;
            } while ($page['has_more']);

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

            $this->writeSyncState($snapshot, 'transaction_links', [
                'mode' => $syncMode,
                'transaction_cursor' => $currentCursor,
                'transaction_modified_since' => $modifiedSince,
                'fetched_count' => $transactionLinkCount,
                'last_offset' => $transactionLinkOffset,
            ], $currentCursor);

            $this->writeSyncState($snapshot, 'transaction_tracking_numbers', [
                'mode' => $syncMode,
                'transaction_cursor' => $currentCursor,
                'transaction_modified_since' => $modifiedSince,
                'fetched_count' => $transactionTrackingNumberCount,
                'last_offset' => $transactionTrackingNumberOffset,
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

    public function syncTransaction(CompanySnapshot $snapshot, int $netsuiteTransactionId): CompanySnapshot
    {
        try {
            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
                'sync_started_at' => now(),
                'last_error' => null,
            ])->save();

            $this->databaseManager->ensureDatabase($snapshot);

            $transaction = $this->netSuite->fetchTransaction($snapshot->netsuite_company_id, $netsuiteTransactionId);

            if ($transaction === null) {
                throw new RuntimeException('Transaction '.$netsuiteTransactionId.' was not found for company '.$snapshot->netsuite_company_id.'.');
            }

            $lines = $this->netSuite->fetchTransactionLines($snapshot->netsuite_company_id, $netsuiteTransactionId);
            $links = $this->netSuite->fetchTransactionLinksForTransaction($snapshot->netsuite_company_id, $netsuiteTransactionId);
            $trackingNumbers = $this->netSuite->fetchTransactionTrackingNumbersForTransactions(
                $snapshot->netsuite_company_id,
                $this->trackingTransactionIds($netsuiteTransactionId, (string) ($transaction['type'] ?? ''), $links),
            );

            $this->writeTransactions($snapshot, [$transaction]);
            $this->writeTransactionLines($snapshot, $lines);
            $this->writeTransactionLinks($snapshot, $links);
            $this->writeTransactionTrackingNumbers($snapshot, $trackingNumbers);

            $this->writeSyncState($snapshot, 'transaction:'.$netsuiteTransactionId, [
                'mode' => 'targeted',
                'transaction_id' => $netsuiteTransactionId,
                'line_count' => count($lines),
                'link_count' => count($links),
                'tracking_number_count' => count($trackingNumbers),
            ], $transaction['last_modified_at'] ?? null);

            $snapshot->forceFill([
                'status' => CompanySnapshot::STATUS_ACTIVE,
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
                'transaction_link_count' => $connection->table('transaction_links')->count(),
                'transaction_tracking_number_count' => $connection->table('transaction_tracking_numbers')->count(),
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
            'trandate' => $this->normalizeDateString($transaction['trandate'] ?? null),
            'total' => $transaction['total'],
            'foreign_total' => $transaction['foreign_total'],
            'currency' => $transaction['currency'],
            'billing_address' => $transaction['billing_address'] ?? null,
            'shipping_address' => $transaction['shipping_address'] ?? null,
            'terms_id' => $transaction['terms_id'] ?? null,
            'terms_name' => $transaction['terms_name'] ?? null,
            'ship_date' => $this->normalizeDateString($transaction['ship_date'] ?? null),
            'ship_method_id' => $transaction['ship_method_id'] ?? null,
            'ship_method_name' => $transaction['ship_method_name'] ?? null,
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
            ['tranid', 'other_ref_num', 'type', 'status', 'trandate', 'total', 'foreign_total', 'currency', 'billing_address', 'shipping_address', 'terms_id', 'terms_name', 'ship_date', 'ship_method_id', 'ship_method_name', 'memo', 'last_modified_at', 'raw_payload', 'synced_at', 'updated_at'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactionLinks
     */
    private function writeTransactionLinks(CompanySnapshot $snapshot, array $transactionLinks): void
    {
        if ($transactionLinks === []) {
            return;
        }

        $now = now();
        $connection = $this->databaseManager->connection($snapshot);

        $rows = collect($transactionLinks)->map(function (array $link) use ($now): array {
            $previousLineId = $link['previous_line_id'] ?? null;
            $nextLineId = $link['next_line_id'] ?? null;
            $linkType = $link['link_type'] ?? null;
            $previousLineKey = $previousLineId === null || $previousLineId === '' ? 'main' : $previousLineId;
            $nextLineKey = $nextLineId === null || $nextLineId === '' ? 'main' : $nextLineId;

            return [
                'link_key' => implode(':', [
                    $link['previous_transaction_netsuite_id'],
                    $previousLineKey,
                    $link['next_transaction_netsuite_id'],
                    $nextLineKey,
                    $linkType === null || $linkType === '' ? 'linked' : $linkType,
                ]),
                'previous_transaction_netsuite_id' => $link['previous_transaction_netsuite_id'],
                'previous_line_id' => $previousLineId,
                'previous_transaction_type' => $link['previous_transaction_type'],
                'previous_transaction_number' => $link['previous_transaction_number'],
                'previous_last_modified_at' => $link['previous_last_modified_at'],
                'next_transaction_netsuite_id' => $link['next_transaction_netsuite_id'],
                'next_line_id' => $nextLineId,
                'next_transaction_type' => $link['next_transaction_type'],
                'next_transaction_number' => $link['next_transaction_number'],
                'next_last_modified_at' => $link['next_last_modified_at'],
                'link_type' => $linkType,
                'raw_payload' => json_encode($link['raw_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        $connection->table('transaction_links')->upsert(
            $rows,
            ['link_key'],
            ['previous_transaction_type', 'previous_transaction_number', 'previous_last_modified_at', 'next_transaction_type', 'next_transaction_number', 'next_last_modified_at', 'link_type', 'raw_payload', 'synced_at', 'updated_at'],
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
            'item_number' => $line['item_number'] ?? $line['item_name'],
            'description' => $line['description'] ?? $line['memo'],
            'quantity' => $line['quantity'],
            'quantity_backordered' => $line['quantity_backordered'] ?? '0.0000',
            'rate' => $line['rate'],
            'amount' => $line['amount'],
            'memo' => $line['memo'],
            'is_mainline' => (bool) ($line['is_mainline'] ?? false),
            'is_tax_line' => (bool) ($line['is_tax_line'] ?? false),
            'is_discount_line' => (bool) ($line['is_discount_line'] ?? false),
            'line_type' => $line['line_type'] ?? null,
            'raw_payload' => json_encode($line['raw_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $connection->table('transaction_lines')->upsert(
            $rows,
            ['transaction_netsuite_id', 'line_id'],
            ['item_id', 'item_name', 'item_number', 'description', 'quantity', 'quantity_backordered', 'rate', 'amount', 'memo', 'is_mainline', 'is_tax_line', 'is_discount_line', 'line_type', 'raw_payload', 'synced_at', 'updated_at'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $trackingNumbers
     */
    private function writeTransactionTrackingNumbers(CompanySnapshot $snapshot, array $trackingNumbers): void
    {
        if ($trackingNumbers === []) {
            return;
        }

        $now = now();
        $connection = $this->databaseManager->connection($snapshot);

        $rows = collect($trackingNumbers)->map(fn (array $trackingNumber): array => [
            'transaction_netsuite_id' => $trackingNumber['transaction_netsuite_id'],
            'tracking_number' => $trackingNumber['tracking_number'],
            'raw_payload' => json_encode($trackingNumber['raw_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $connection->table('transaction_tracking_numbers')->upsert(
            $rows,
            ['transaction_netsuite_id', 'tracking_number'],
            ['raw_payload', 'synced_at', 'updated_at'],
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

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array<int, int>
     */
    private function trackingTransactionIds(int $netsuiteTransactionId, string $transactionType, array $links): array
    {
        $ids = $transactionType === 'ItemShip' ? [$netsuiteTransactionId] : [];

        foreach ($links as $link) {
            if (($link['previous_transaction_type'] ?? null) === 'ItemShip') {
                $ids[] = (int) $link['previous_transaction_netsuite_id'];
            }

            if (($link['next_transaction_type'] ?? null) === 'ItemShip') {
                $ids[] = (int) $link['next_transaction_netsuite_id'];
            }
        }

        return collect($ids)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
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

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
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
