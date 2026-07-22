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

    /**
     * @param  array<int, string>  $types
     */
    public function documentNumber(CompanySnapshot $snapshot, int $netsuiteTransactionId, array $types = []): ?string
    {
        $transaction = $this->databaseManager
            ->connection($snapshot)
            ->table('transactions')
            ->select(['netsuite_id', 'tranid'])
            ->where('netsuite_id', $netsuiteTransactionId)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->first();

        if ($transaction === null) {
            return null;
        }

        return filled($transaction->tranid) ? (string) $transaction->tranid : (string) $transaction->netsuite_id;
    }

    public function displayLines(CompanySnapshot $snapshot, int $netsuiteTransactionId): Collection
    {
        $transaction = $this->find($snapshot, $netsuiteTransactionId);

        return $this->lines($snapshot, $netsuiteTransactionId)
            ->reject(fn (object $line): bool => $this->isNonMerchandiseLine($transaction, $line))
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
        $explicitFreight = $allLines
            ->filter(fn (object $line): bool => $this->isFreightLine($transaction, $line))
            ->sum(fn (object $line): float => abs((float) $line->amount));
        $total = abs((float) ($transaction->foreign_total ?: $transaction->total));
        $freight = $explicitFreight > 0
            ? round($explicitFreight, 2)
            : max(0.0, round($total - $subtotal + $discount, 2));

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

    private function isNonMerchandiseLine(?object $transaction, object $line): bool
    {
        return (bool) $line->is_mainline
            || (bool) $line->is_tax_line
            || (bool) $line->is_discount_line
            || ($transaction !== null && $this->isFreightLine($transaction, $line));
    }

    private function isFreightLine(object $transaction, object $line): bool
    {
        if ((bool) $line->is_mainline || (bool) $line->is_tax_line || (bool) $line->is_discount_line) {
            return false;
        }

        $payload = json_decode((string) $line->raw_payload, true);

        if (is_array($payload) && $this->truthyString($payload['shipping'] ?? null)) {
            return true;
        }

        $itemId = trim((string) $line->item_id);
        $shipMethodId = trim((string) ($transaction->ship_method_id ?? ''));

        if ($itemId !== '' && $shipMethodId !== '' && $itemId === $shipMethodId) {
            return true;
        }

        if (abs((float) $line->amount) === 0.0) {
            return false;
        }

        if (filled($line->item_number) || filled($line->description)) {
            return false;
        }

        $memo = strtolower(trim((string) $line->memo));

        if ($memo === '') {
            return true;
        }

        return str_contains($memo, 'shipping')
            || str_contains($memo, 'freight')
            || str_contains($memo, 'ups')
            || str_contains($memo, 'fedex')
            || str_contains($memo, 'usps')
            || str_contains($memo, 'ground')
            || str_contains($memo, 'delivery')
            || str_contains($memo, 'best way');
    }

    private function truthyString(mixed $value): bool
    {
        return in_array(strtoupper((string) $value), ['T', 'TRUE', '1', 'Y', 'YES'], true);
    }
}
