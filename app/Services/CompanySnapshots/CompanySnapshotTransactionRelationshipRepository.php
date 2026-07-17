<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Support\Collection;

class CompanySnapshotTransactionRelationshipRepository
{
    public function __construct(
        private readonly CompanySnapshotDatabaseManager $databaseManager,
    ) {}

    public function linksForTransaction(CompanySnapshot $snapshot, int $netsuiteTransactionId): Collection
    {
        return $this->databaseManager
            ->connection($snapshot)
            ->table('transaction_links')
            ->where('previous_transaction_netsuite_id', $netsuiteTransactionId)
            ->orWhere('next_transaction_netsuite_id', $netsuiteTransactionId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<int, string>  $types
     */
    public function relatedTransactions(
        CompanySnapshot $snapshot,
        int $netsuiteTransactionId,
        array $types = [],
        int $maxDepth = 1,
    ): Collection {
        $relatedTransactionIds = $this->relatedTransactionIds($snapshot, $netsuiteTransactionId, $maxDepth);

        if ($relatedTransactionIds === []) {
            return collect();
        }

        return $this->databaseManager
            ->connection($snapshot)
            ->table('transactions')
            ->whereIn('netsuite_id', $relatedTransactionIds)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->orderByDesc('trandate')
            ->orderByDesc('netsuite_id')
            ->get();
    }

    public function financialDocumentsForSalesOrder(CompanySnapshot $snapshot, int $salesOrderNetsuiteId): Collection
    {
        return $this->relatedTransactions(
            snapshot: $snapshot,
            netsuiteTransactionId: $salesOrderNetsuiteId,
            types: ['CustInvc', 'CustCred'],
            maxDepth: 2,
        );
    }

    public function invoicesForSalesOrder(CompanySnapshot $snapshot, int $salesOrderNetsuiteId): Collection
    {
        return $this->relatedTransactions(
            snapshot: $snapshot,
            netsuiteTransactionId: $salesOrderNetsuiteId,
            types: ['CustInvc'],
            maxDepth: 2,
        );
    }

    public function creditMemosForSalesOrder(CompanySnapshot $snapshot, int $salesOrderNetsuiteId): Collection
    {
        return $this->relatedTransactions(
            snapshot: $snapshot,
            netsuiteTransactionId: $salesOrderNetsuiteId,
            types: ['CustCred'],
            maxDepth: 2,
        );
    }

    /**
     * @return array<int, int>
     */
    private function relatedTransactionIds(CompanySnapshot $snapshot, int $netsuiteTransactionId, int $maxDepth): array
    {
        $connection = $this->databaseManager->connection($snapshot);
        $visited = [$netsuiteTransactionId => true];
        $frontier = [$netsuiteTransactionId];
        $related = [];
        $maxDepth = max(1, $maxDepth);

        for ($depth = 0; $depth < $maxDepth && $frontier !== []; $depth++) {
            $links = $connection
                ->table('transaction_links')
                ->whereIn('previous_transaction_netsuite_id', $frontier)
                ->orWhereIn('next_transaction_netsuite_id', $frontier)
                ->get();

            $nextFrontier = [];

            foreach ($links as $link) {
                foreach ([
                    (int) $link->previous_transaction_netsuite_id,
                    (int) $link->next_transaction_netsuite_id,
                ] as $linkedTransactionId) {
                    if (isset($visited[$linkedTransactionId])) {
                        continue;
                    }

                    $visited[$linkedTransactionId] = true;
                    $related[$linkedTransactionId] = $linkedTransactionId;
                    $nextFrontier[] = $linkedTransactionId;
                }
            }

            $frontier = array_values(array_unique($nextFrontier));
        }

        return array_values($related);
    }
}
