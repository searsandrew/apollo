<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

class CompanySnapshotInvoiceRepository
{
    public const int DEFAULT_PER_PAGE = 12;

    public const string PAGE_NAME = 'invoices-page';

    public const string DEFAULT_SORT_BY = 'date';

    public const string DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * @var array<int, string>
     */
    private const array INVOICE_TYPES = ['CustInvc', 'CustCred'];

    /**
     * @var array<string, string>
     */
    private const array SORTABLE_COLUMNS = [
        'date' => 'trandate',
        'po_number' => 'other_ref_num',
        'invoice_number' => 'tranid',
        'type' => 'type',
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
        ?string $search = null,
        array $netsuiteIds = [],
    ): LengthAwarePaginator {
        return $this->invoiceQuery($snapshot)
            ->tap(fn (Builder $query): Builder => $this->applyFilters($query, $search, $netsuiteIds))
            ->tap(fn (Builder $query): Builder => $this->applySort($query, $sortBy, $sortDirection))
            ->paginate($perPage, $columns, $pageName);
    }

    public function isInvoiceType(?string $type): bool
    {
        return in_array($type, self::INVOICE_TYPES, true);
    }

    public function isSortable(string $column): bool
    {
        return array_key_exists($column, self::SORTABLE_COLUMNS);
    }

    public function defaultDirectionFor(string $column): string
    {
        return match ($column) {
            'status', 'type' => 'asc',
            default => 'desc',
        };
    }

    private function invoiceQuery(CompanySnapshot $snapshot): Builder
    {
        return $this->databaseManager
            ->connection($snapshot)
            ->table('transactions')
            ->whereIn('type', self::INVOICE_TYPES);
    }

    /**
     * @param  array<int, int>  $netsuiteIds
     */
    private function applyFilters(Builder $query, ?string $search, array $netsuiteIds): Builder
    {
        $netsuiteIds = array_values(array_unique(array_filter($netsuiteIds)));

        if ($netsuiteIds !== []) {
            $query->whereIn('netsuite_id', $netsuiteIds);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $query) use ($like, $search): void {
                $query->where('tranid', 'like', $like)
                    ->orWhere('other_ref_num', 'like', $like);

                if (ctype_digit($search)) {
                    $query->orWhere('netsuite_id', (int) $search);
                }
            });
        }

        return $query;
    }

    private function applySort(Builder $query, string $sortBy, string $sortDirection): Builder
    {
        $sortBy = $this->isSortable($sortBy) ? $sortBy : self::DEFAULT_SORT_BY;
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'invoice_number') {
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
