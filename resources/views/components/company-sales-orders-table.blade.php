<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /**
     * @var array<string, string>
     */
    private const array SALES_ORDER_STATUS_LABELS = [
        'A' => 'Pending Approval',
        'B' => 'Pending Fulfillment',
        'C' => 'Cancelled',
        'D' => 'Partially Fulfilled',
        'E' => 'Pending Billing/Partially Fulfilled',
        'F' => 'Pending Billing',
        'G' => 'Billed',
        'H' => 'Closed',
    ];

    /**
     * @var array<string, string>
     */
    private const array SALES_ORDER_STATUS_COLORS = [
        'A' => 'amber',
        'B' => 'sky',
        'C' => 'red',
        'D' => 'blue',
        'E' => 'violet',
        'F' => 'purple',
        'G' => 'green',
        'H' => 'zinc',
    ];

    private const int TRANSACTION_STALE_DAYS = 1;

    public int $snapshotId;

    public string $sortBy = CompanySnapshotSalesOrderRepository::DEFAULT_SORT_BY;

    public string $sortDirection = CompanySnapshotSalesOrderRepository::DEFAULT_SORT_DIRECTION;

    private CompanySnapshotSalesOrderRepository $salesOrderRepository;

    public function boot(CompanySnapshotSalesOrderRepository $salesOrderRepository): void
    {
        $this->salesOrderRepository = $salesOrderRepository;
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

    public function salesOrderStatusLabel(?string $status): string
    {
        if (blank($status)) {
            return '-';
        }

        $status = trim($status);

        if (array_key_exists($status, self::SALES_ORDER_STATUS_LABELS)) {
            return self::SALES_ORDER_STATUS_LABELS[$status];
        }

        return $status;
    }

    public function salesOrderStatusColor(?string $status): string
    {
        if (blank($status)) {
            return 'zinc';
        }

        return self::SALES_ORDER_STATUS_COLORS[trim($status)] ?? 'zinc';
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
};
?>

<div @if ($this->shouldPoll) wire:poll.visible.5s @endif>
    <div class="mb-3 flex w-full flex-row items-center justify-between gap-4">
        <div class="flex flex-col">
            <flux:heading size="lg">{{ __('Sales Orders') }}</flux:heading>
            <flux:text>{{ __('Orders currently submitted and processed by :company.', ['company' => config('app.name')]) }}</flux:text>
        </div>

        <div class="flex shrink-0 flex-col items-end gap-1">
            @if ($this->syncActivityLabel())
                <flux:badge size="sm" color="sky" icon="arrow-path" class="animate-pulse">{{ $this->syncActivityLabel() }}</flux:badge>
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

    @if ($this->salesOrders->count() === 0)
        <div class="rounded-lg border border-dashed border-zinc-200 p-6 dark:border-zinc-700">
            <flux:heading size="sm">{{ $this->shouldPoll ? __('Sales orders are syncing') : __('No sales orders found') }}</flux:heading>
            <flux:text>
                {{ $this->shouldPoll
                    ? __('Sales orders are still being pulled in from our servers.')
                    : __('No sales orders were found in the company data source.') }}
            </flux:text>
        </div>
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
                    @php($statusLabel = $this->salesOrderStatusLabel($salesOrder->status))

                    <flux:table.row wire:key="sales-order-{{ $salesOrder->netsuite_id }}">
                        <flux:table.cell class="w-36 font-medium">
                            <span class="block truncate" title="{{ $salesOrderNumber }}">{{ $salesOrderNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="w-56">
                            <span class="block truncate" title="{{ $poNumber }}">{{ $poNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ blank($salesOrder->trandate) ? '-' : Carbon::parse((string) $salesOrder->trandate)->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell align="center" class="w-44">
                            <flux:badge size="sm" class="max-w-40" :color="$this->salesOrderStatusColor($salesOrder->status)" inset="top bottom" title="{{ $statusLabel }}">
                                <span class="block truncate">{{ $statusLabel }}</span>
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ Number::currency((float) $salesOrder->total, in: $this->currencyCode($salesOrder->currency)) }}</flux:table.cell>
                        <flux:table.cell align="end" class="w-12 py-0">
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="{{ __('Order actions') }}"></flux:button>
                                <flux:menu>
                                    @can('create order')
                                        <flux:menu.item icon="document-text">{{ __('View Purchase Order') }}</flux:menu.item>
                                    @endcan
                                    @can('view order')
                                        <flux:menu.item icon="document-magnifying-glass">{{ __('View Sales Order') }}</flux:menu.item>
                                    @endcan
                                    @can('view invoice')
                                        <flux:menu.item icon="document-currency-dollar">{{ __('View Invoice') }}</flux:menu.item>
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
