<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Support\Collection;

class CompanySnapshotTransactionDetailRepository
{
    public function __construct(
        private readonly CompanySnapshotDatabaseManager $databaseManager,
        private readonly CompanySnapshotTransactionRelationshipRepository $relationshipRepository,
    ) {}

    /**
     * @param  array<int, string>  $types
     */
    public function find(CompanySnapshot $snapshot, int $netsuiteTransactionId, array $types = []): ?object
    {
        return $this->databaseManager
            ->connection($snapshot)
            ->table('transactions')
            ->where('netsuite_id', $netsuiteTransactionId)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->first();
    }

    public function displayLines(CompanySnapshot $snapshot, int $netsuiteTransactionId): Collection
    {
        return $this->lines($snapshot, $netsuiteTransactionId)
            ->reject(fn (object $line): bool => (bool) $line->is_mainline || (bool) $line->is_tax_line || (bool) $line->is_discount_line)
            ->filter(fn (object $line): bool => filled($line->item_number) || filled($line->description) || (float) $line->amount !== 0.0)
            ->values();
    }

    public function trackingNumbers(CompanySnapshot $snapshot, object $transaction): Collection
    {
        $fulfillmentIds = $this->fulfillmentIds($snapshot, (int) $transaction->netsuite_id, (string) $transaction->type);

        if ($fulfillmentIds === []) {
            return collect();
        }

        return $this->databaseManager
            ->connection($snapshot)
            ->table('transaction_tracking_numbers')
            ->whereIn('transaction_netsuite_id', $fulfillmentIds)
            ->orderBy('tracking_number')
            ->pluck('tracking_number')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return array{subtotal: float, discount: float, freight: float, total: float}
     */
    public function totals(object $transaction, Collection $displayLines, Collection $allLines): array
    {
        $subtotal = $displayLines->sum(fn (object $line): float => abs((float) $line->amount));
        $discount = $allLines
            ->filter(fn (object $line): bool => (bool) $line->is_discount_line)
            ->sum(fn (object $line): float => abs((float) $line->amount));
        $total = abs((float) ($transaction->foreign_total ?: $transaction->total));
        $freight = max(0.0, round($total - $subtotal + $discount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'freight' => $freight,
            'total' => $total,
        ];
    }

    public function lines(CompanySnapshot $snapshot, int $netsuiteTransactionId): Collection
    {
        return $this->databaseManager
            ->connection($snapshot)
            ->table('transaction_lines')
            ->where('transaction_netsuite_id', $netsuiteTransactionId)
            ->orderByRaw('CAST(coalesce(line_id, 0) AS INTEGER)')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function fulfillmentIds(CompanySnapshot $snapshot, int $netsuiteTransactionId, string $type): array
    {
        $fulfillmentIds = [];

        if ($type === 'ItemShip') {
            $fulfillmentIds[] = $netsuiteTransactionId;
        }

        $relatedFulfillmentIds = $this->relationshipRepository
            ->relatedTransactions(
                snapshot: $snapshot,
                netsuiteTransactionId: $netsuiteTransactionId,
                types: ['ItemShip'],
                maxDepth: 3,
            )
            ->pluck('netsuite_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return collect([...$fulfillmentIds, ...$relatedFulfillmentIds])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
