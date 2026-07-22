<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSalesOrderRepository;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use App\Services\CompanySnapshots\CompanySnapshotTransactionRelationshipRepository;
use App\Services\NetSuite\NetSuiteTransactionStatusMapper;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private const int TRANSACTION_STALE_DAYS = 1;

    public int $snapshotId;

    public string $sortBy = CompanySnapshotSalesOrderRepository::DEFAULT_SORT_BY;

    public string $sortDirection = CompanySnapshotSalesOrderRepository::DEFAULT_SORT_DIRECTION;

    public string $search = '';

    public string $related = '';

    public string $source = '';

    private CompanySnapshotSalesOrderRepository $salesOrderRepository;

    private CompanySnapshotTransactionRelationshipRepository $relationshipRepository;

    private CompanySnapshotSyncer $snapshotSyncer;

    private NetSuiteTransactionStatusMapper $statusMapper;

    public function boot(
        CompanySnapshotSalesOrderRepository $salesOrderRepository,
        CompanySnapshotTransactionRelationshipRepository $relationshipRepository,
        CompanySnapshotSyncer $snapshotSyncer,
        NetSuiteTransactionStatusMapper $statusMapper,
    ): void {
        $this->salesOrderRepository = $salesOrderRepository;
        $this->relationshipRepository = $relationshipRepository;
        $this->snapshotSyncer = $snapshotSyncer;
        $this->statusMapper = $statusMapper;
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function queryString(): array
    {
        return [
            'search' => ['as' => 'q', 'except' => ''],
            'related' => ['except' => ''],
            'source' => ['except' => ''],
        ];
    }

    #[Computed]
    public function snapshot(): CompanySnapshot
    {
        return CompanySnapshot::query()->findOrFail($this->snapshotId);
    }

    #[Computed]
    public function salesOrders(): LengthAwarePaginator
    {
        return $this->salesOrderRepository->paginate(
            snapshot: $this->snapshot,
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            search: $this->search,
            netsuiteIds: $this->relatedIds(),
        );
    }

    #[Computed]
    public function shouldPoll(): bool
    {
        return $this->snapshot->transactions_synced_at === null
            || $this->isSyncing()
            || $this->snapshot->isMetaStale()
            || $this->snapshot->areTransactionsStale(self::TRANSACTION_STALE_DAYS);
    }

    public function syncActivityLabel(): ?string
    {
        if ($this->isSyncing()) {
            return __('Refreshing data');
        }

        if ($this->shouldPoll) {
            return __('Checking for updates');
        }

        return null;
    }

    public function syncStartedAt(): ?CarbonInterface
    {
        if ($this->snapshot->transactions_synced_at === null) {
            return $this->snapshot->created_at;
        }

        return $this->snapshot->updated_at;
    }

    public function refreshSyncState(): void
    {
        unset($this->snapshot, $this->salesOrders);

        if (! $this->shouldPoll || $this->isSyncing()) {
            return;
        }

        if (! Cache::add($this->refreshQueueCacheKey(), true, now()->addMinute())) {
            return;
        }

        $this->snapshotSyncer->queueRefreshIfStale(
            $this->snapshot,
            transactionStaleDays: self::TRANSACTION_STALE_DAYS,
        );
    }

    public function isSyncing(): bool
    {
        return in_array($this->snapshot->status, [
            CompanySnapshot::STATUS_SYNCING_META,
            CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
        ], true);
    }

    public function sort(string $column): void
    {
        if (! $this->salesOrderRepository->isSortable($column)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = $this->salesOrderRepository->defaultDirectionFor($column);
        }

        $this->resetPage(pageName: CompanySnapshotSalesOrderRepository::PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        unset($this->salesOrders);

        $this->resetPage(pageName: CompanySnapshotSalesOrderRepository::PAGE_NAME);
    }

    public function updatedRelated(): void
    {
        unset($this->salesOrders);

        $this->resetPage(pageName: CompanySnapshotSalesOrderRepository::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->related = '';
        $this->source = '';

        unset($this->salesOrders);

        $this->resetPage(pageName: CompanySnapshotSalesOrderRepository::PAGE_NAME);
    }

    public function hasFilters(): bool
    {
        return trim($this->search) !== '' || $this->relatedIds() !== [];
    }

    /**
     * @return array<int, int>
     */
    public function relatedIds(): array
    {
        return collect(explode(',', $this->related))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function relatedInvoicesUrl(int $salesOrderNetsuiteId, string $salesOrderNumber): ?string
    {
        return $this->relatedDocumentsUrl($salesOrderNetsuiteId, $salesOrderNumber, ['CustInvc']);
    }

    public function relatedCreditMemosUrl(int $salesOrderNetsuiteId, string $salesOrderNumber): ?string
    {
        return $this->relatedDocumentsUrl($salesOrderNetsuiteId, $salesOrderNumber, ['CustCred']);
    }

    public function transactionStatusLabel(?string $type, ?string $status): string
    {
        return $this->statusMapper->label($type, $status);
    }

    public function transactionStatusColor(?string $type, ?string $status): string
    {
        return $this->statusMapper->color($type, $status);
    }

    public function currencyCode(mixed $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        if ($currency === '' || $currency === '1' || str_contains($currency, 'US DOLLAR')) {
            return 'USD';
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            return $currency;
        }

        return 'USD';
    }

    private function refreshQueueCacheKey(): string
    {
        return 'company-snapshot:refresh-queued:'.$this->snapshotId;
    }

    /**
     * @param  array<int, string>  $types
     */
    private function relatedDocumentsUrl(int $salesOrderNetsuiteId, string $salesOrderNumber, array $types): ?string
    {
        $documents = $this->relationshipRepository->relatedTransactions(
            snapshot: $this->snapshot,
            netsuiteTransactionId: $salesOrderNetsuiteId,
            types: $types,
            maxDepth: 2,
        );

        if ($documents->isEmpty()) {
            return null;
        }

        return route('company.invoices.index', [
            'company' => $this->snapshot->netsuite_company_id,
            'related' => $documents->pluck('netsuite_id')->implode(','),
            'source' => $salesOrderNumber,
        ]);
    }
};
?>

<div @if ($this->shouldPoll) wire:poll.visible.5s="refreshSyncState" @endif>
    <div class="mb-3 flex w-full flex-row items-center justify-between gap-4">
        <div class="flex flex-col">
            <flux:heading size="lg">{{ __('Sales Orders') }}</flux:heading>
            <flux:text>{{ __('Orders currently submitted and processed by :company.', ['company' => config('app.name')]) }}</flux:text>
        </div>

        <div class="flex shrink-0 flex-col items-end gap-1">
            @if ($this->syncActivityLabel())
                <flux:badge size="sm" color="sky">
                    <span class="inline-flex items-center gap-1.5">
                        <flux:icon.loading class="size-3" />
                        <span wire:loading.remove>{{ $this->syncActivityLabel() }}</span>
                        <span wire:loading>{{ __('Checking now') }}</span>
                    </span>
                </flux:badge>
            @endif

            @php($transactionsSyncedAt = $this->snapshot->transactions_synced_at)

            @if ($transactionsSyncedAt !== null)
                <small
                    wire:key="sales-orders-synced-at-{{ $transactionsSyncedAt->getTimestamp() }}"
                    class="italic text-zinc-600 dark:text-zinc-400"
                    aria-live="polite"
                    x-data="relativeTime({
                        timestamp: @js($transactionsSyncedAt->toIso8601String()),
                        fallback: @js($transactionsSyncedAt->diffForHumans()),
                    })"
                >
                    {{ __('Last synced') }} <span x-text="label">{{ $transactionsSyncedAt->diffForHumans() }}</span>
                </small>
            @else
                <small class="italic text-zinc-600 dark:text-zinc-400">{{ __('Waiting for first sync') }}</small>
            @endif
        </div>
    </div>

    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:input class="sm:max-w-sm" size="sm" icon="magnifying-glass" clearable wire:model.live.debounce.300ms="search" placeholder="{{ __('Search sales order or PO number') }}" />

        @if ($this->hasFilters())
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->relatedIds() !== [])
                    <flux:badge size="sm" color="zinc">{{ __('Related to :source', ['source' => $source ?: __('selected record')]) }}</flux:badge>
                @endif

                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($this->salesOrders->count() === 0)
        @if ($this->shouldPoll && ! $this->hasFilters())
            @php($syncStartedAt = $this->syncStartedAt())

            <div class="rounded-lg border border-dashed border-sky-200 bg-sky-50/40 p-6 dark:border-sky-900/70 dark:bg-sky-950/20">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300">
                        <flux:icon.loading class="size-5" />
                    </div>

                    <div class="min-w-0">
                        <flux:heading size="sm">{{ __('Sales orders are syncing') }}</flux:heading>
                        <flux:text>{{ __('We are checking our servers. This page will update automatically as soon as sales orders are available.') }}</flux:text>

                        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400" aria-live="polite">
                            <span wire:loading.remove>{{ __('Waiting for next check') }}</span>
                            <span wire:loading>{{ __('Checking now') }}</span>

                            @if ($syncStartedAt !== null)
                                <span aria-hidden="true">&middot;</span>
                                <span
                                    x-data="relativeTime({
                                        timestamp: @js($syncStartedAt->toIso8601String()),
                                        fallback: @js($syncStartedAt->diffForHumans()),
                                    })"
                                >
                                    {{ __('Started') }} <span x-text="label">{{ $syncStartedAt->diffForHumans() }}</span>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="sm">{{ $this->hasFilters() ? __('No matching sales orders found') : __('No sales orders found') }}</flux:heading>
                <flux:text>{{ $this->hasFilters() ? __('No sales orders matched the current search or relationship filter.') : __('No sales orders were found in the company data source.') }}</flux:text>
            </div>
        @endif
    @else
        <flux:table class="w-full" :paginate="$this->salesOrders">
            <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                <flux:table.column sortable class="w-36" :sorted="$sortBy === 'sales_order_number'" :direction="$sortDirection" wire:click="sort('sales_order_number')">{{ __('Sales Order') }}</flux:table.column>
                <flux:table.column sortable class="w-56" :sorted="$sortBy === 'po_number'" :direction="$sortDirection" wire:click="sort('po_number')">{{ __('PO Number') }}</flux:table.column>
                <flux:table.column sortable class="w-32" :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">{{ __('Date') }}</flux:table.column>
                <flux:table.column sortable align="center" class="w-44" :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
                <flux:table.column sortable class="w-28" :sorted="$sortBy === 'total'" :direction="$sortDirection" wire:click="sort('total')">{{ __('Total') }}</flux:table.column>
                <flux:table.column align="end" class="w-12"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->salesOrders as $salesOrder)
                    @php($salesOrderNumber = $salesOrder->tranid ?: $salesOrder->netsuite_id)
                    @php($poNumber = $salesOrder->other_ref_num ?: '-')
                    @php($statusLabel = $this->transactionStatusLabel($salesOrder->type, $salesOrder->status))
                    @php($documentUrl = route('company.sales-orders.show', [$this->snapshot->netsuite_company_id, $salesOrder->netsuite_id]))
                    @php($relatedInvoicesUrl = $this->relatedInvoicesUrl((int) $salesOrder->netsuite_id, (string) $salesOrderNumber))
                    @php($relatedCreditMemosUrl = $this->relatedCreditMemosUrl((int) $salesOrder->netsuite_id, (string) $salesOrderNumber))

                    <flux:table.row
                        wire:key="sales-order-{{ $salesOrder->netsuite_id }}"
                        class="cursor-pointer transition-colors hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-white/5"
                        role="link"
                        tabindex="0"
                        data-href="{{ $documentUrl }}"
                        aria-label="{{ __('View sales order :number', ['number' => $salesOrderNumber]) }}"
                        x-on:click="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.enter.prevent="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.space.prevent="Livewire.navigate($el.dataset.href)"
                    >
                        <flux:table.cell class="w-36 font-medium">
                            <span class="block truncate" title="{{ $salesOrderNumber }}">{{ $salesOrderNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="w-56">
                            <span class="block truncate" title="{{ $poNumber }}">{{ $poNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ blank($salesOrder->trandate) ? '-' : Carbon::parse((string) $salesOrder->trandate)->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell align="center" class="w-44">
                            <flux:badge size="sm" class="max-w-40" :color="$this->transactionStatusColor($salesOrder->type, $salesOrder->status)" inset="top bottom" title="{{ $statusLabel }}">
                                <span class="block truncate">{{ $statusLabel }}</span>
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ Number::currency((float) $salesOrder->total, in: $this->currencyCode($salesOrder->currency)) }}</flux:table.cell>
                        <flux:table.cell align="end" class="w-12 py-0" x-on:click.stop x-on:keydown.stop>
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="{{ __('Order actions') }}"></flux:button>
                                <flux:menu>
                                    @can('create order')
                                        <flux:menu.item icon="document-text">{{ __('View Purchase Order') }}</flux:menu.item>
                                    @endcan
                                    @can('view order')
                                        <flux:menu.item icon="document-magnifying-glass" :href="$documentUrl" wire:navigate>{{ __('View Sales Order') }}</flux:menu.item>
                                    @endcan
                                    @can('view invoice')
                                        @if ($relatedInvoicesUrl !== null)
                                            <flux:menu.item icon="document-currency-dollar" :href="$relatedInvoicesUrl" wire:navigate>{{ __('View Invoices') }}</flux:menu.item>
                                        @endif
                                        @if ($relatedCreditMemosUrl !== null)
                                            <flux:menu.item icon="receipt-refund" :href="$relatedCreditMemosUrl" wire:navigate>{{ __('View Credit Memos') }}</flux:menu.item>
                                        @endif
                                    @endcan
                                    <flux:menu.separator />
                                    @can('view order')
                                        <flux:menu.item icon="truck">{{ __('Track Shipment') }}</flux:menu.item>
                                    @endcan
                                    @can('create return')
                                        <flux:menu.item icon="receipt-refund">{{ __('Return Good Authorization') }}</flux:menu.item>
                                    @endcan
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
