<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

class CompanySnapshotSalesOrderRepository
{
    public const int DEFAULT_PER_PAGE = 12;

    public const string PAGE_NAME = 'sales-orders-page';

    public const string DEFAULT_SORT_BY = 'sales_order_number';

    public const string DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * @var array<int, string>
     */
    private const array SALES_ORDER_TYPES = ['SalesOrd'];

    /**
     * @var array<string, string>
     */
    private const array SORTABLE_COLUMNS = [
        'date' => 'trandate',
        'po_number' => 'other_ref_num',
        'sales_order_number' => 'tranid',
        'status' => 'status',
        'total' => 'total',
    ];

    public function __construct(
        private readonly CompanySnapshotDatabaseManager $databaseManager,
    ) {}

    /**
     * @param  array<int, string>  $columns
     */
    public function paginate(
        CompanySnapshot $snapshot,
        int $perPage = self::DEFAULT_PER_PAGE,
        string $pageName = self::PAGE_NAME,
        array $columns = ['*'],
        string $sortBy = self::DEFAULT_SORT_BY,
        string $sortDirection = self::DEFAULT_SORT_DIRECTION,
    ): LengthAwarePaginator {
        return $this->salesOrderQuery($snapshot)
            ->tap(fn (Builder $query): Builder => $this->applySort($query, $sortBy, $sortDirection))
            ->paginate($perPage, $columns, $pageName);
    }

    public function isSalesOrderType(?string $type): bool
    {
        return in_array($type, self::SALES_ORDER_TYPES, true);
    }

    public function isSortable(string $column): bool
    {
        return array_key_exists($column, self::SORTABLE_COLUMNS);
    }

    public function defaultDirectionFor(string $column): string
    {
        return match ($column) {
            'status' => 'asc',
            default => 'desc',
        };
    }

    private function salesOrderQuery(CompanySnapshot $snapshot): Builder
    {
        return $this->databaseManager
            ->connection($snapshot)
            ->table('transactions')
            ->whereIn('type', self::SALES_ORDER_TYPES);
    }

    private function applySort(Builder $query, string $sortBy, string $sortDirection): Builder
    {
        $sortBy = $this->isSortable($sortBy) ? $sortBy : self::DEFAULT_SORT_BY;
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'sales_order_number') {
            return $query
                ->orderByRaw('length(coalesce(tranid, \'\')) '.$sortDirection)
                ->orderBy('tranid', $sortDirection)
                ->orderBy('netsuite_id', $sortDirection);
        }

        return $query
            ->orderBy(self::SORTABLE_COLUMNS[$sortBy], $sortDirection)
            ->orderByDesc('netsuite_id');
    }
}
