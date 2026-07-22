<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotTransactionDetailRepository;
use App\Services\NetSuite\NetSuiteTransactionStatusMapper;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $snapshotId;

    public int $transactionId;

    /**
     * @var array<int, string>
     */
    public array $types = [];

    public string $documentLabel = 'Transaction';

    public string $numberLabel = 'Transaction #';

    private CompanySnapshotTransactionDetailRepository $transactionRepository;

    private NetSuiteTransactionStatusMapper $statusMapper;

    public function boot(
        CompanySnapshotTransactionDetailRepository $transactionRepository,
        NetSuiteTransactionStatusMapper $statusMapper,
    ): void {
        $this->transactionRepository = $transactionRepository;
        $this->statusMapper = $statusMapper;
    }

    #[Computed]
    public function snapshot(): CompanySnapshot
    {
        return CompanySnapshot::query()->findOrFail($this->snapshotId);
    }

    #[Computed]
    public function transaction(): ?object
    {
        return $this->transactionRepository->find($this->snapshot, $this->transactionId, $this->types);
    }

    #[Computed]
    public function allLines(): Collection
    {
        if ($this->transaction === null) {
            return collect();
        }

        return $this->transactionRepository->lines($this->snapshot, (int) $this->transaction->netsuite_id);
    }

    #[Computed]
    public function displayLines(): Collection
    {
        if ($this->transaction === null) {
            return collect();
        }

        return $this->transactionRepository->displayLines($this->snapshot, (int) $this->transaction->netsuite_id);
    }

    #[Computed]
    public function trackingNumbers(): Collection
    {
        if ($this->transaction === null) {
            return collect();
        }

        return $this->transactionRepository->trackingNumbers($this->snapshot, $this->transaction);
    }

    /**
     * @return array{subtotal: float, discount: float, freight: float, total: float}
     */
    public function totals(): array
    {
        if ($this->transaction === null) {
            return [
                'subtotal' => 0.0,
                'discount' => 0.0,
                'freight' => 0.0,
                'total' => 0.0,
            ];
        }

        return $this->transactionRepository->totals($this->transaction, $this->displayLines, $this->allLines);
    }

    public function statusLabel(?string $type, ?string $status): string
    {
        return $this->statusMapper->label($type, $status);
    }

    public function statusColor(?string $type, ?string $status): string
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

    /**
     * @return array<int, string>
     */
    public function addressLines(?string $address): array
    {
        return collect(preg_split('/\r\n|\r|\n/', trim((string) $address)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    public function formattedDate(mixed $date): string
    {
        if (blank($date)) {
            return '-';
        }

        return Carbon::parse((string) $date)->format('m/d/Y');
    }

    public function formattedMoney(mixed $amount): string
    {
        return Number::currency((float) $amount, in: $this->currencyCode($this->transaction?->currency));
    }

    public function formattedQuantity(mixed $quantity): string
    {
        $quantity = abs((float) $quantity);

        return rtrim(rtrim(number_format($quantity, 4), '0'), '.') ?: '0';
    }

    public function showLineItemNumbers(): bool
    {
        return $this->displayLines->contains(fn (object $line): bool => filled($this->lineItemNumber($line)));
    }

    public function showBackorderColumn(): bool
    {
        return $this->transaction?->type === 'SalesOrd';
    }

    public function lineTableColumnCount(): int
    {
        return 4
            + ($this->showLineItemNumbers() ? 1 : 0)
            + ($this->showBackorderColumn() ? 1 : 0);
    }

    public function lineDescription(object $line): string
    {
        return $line->description ?: $line->memo ?: '-';
    }

    public function lineItemNumber(object $line): string
    {
        return $line->item_number ?: $line->item_name ?: '';
    }

    public function syncDate(): ?CarbonInterface
    {
        if ($this->transaction === null || blank($this->transaction->synced_at)) {
            return null;
        }

        return Carbon::parse((string) $this->transaction->synced_at);
    }
};
?>

@if ($this->transaction === null)
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-white/10 dark:bg-zinc-900">
        <flux:heading size="lg">{{ __('Transaction not found') }}</flux:heading>
        <flux:text class="mt-2">{{ __('This record is not available in the company snapshot yet.') }}</flux:text>
    </div>
@else
    @php($transaction = $this->transaction)
    @php($documentNumber = $transaction->tranid ?: $transaction->netsuite_id)
    @php($billToLines = $this->addressLines($transaction->billing_address))
    @php($shipToLines = $this->addressLines($transaction->shipping_address))
    @php($totals = $this->totals())
    @php($showLineItemNumbers = $this->showLineItemNumbers())
    @php($showBackorderColumn = $this->showBackorderColumn())
    @php($lineTableClass = $showLineItemNumbers || $showBackorderColumn ? 'w-full min-w-[760px] table-fixed' : 'w-full min-w-[560px] table-fixed')

    <div class="rounded-lg border border-zinc-200 bg-white p-4 text-sm shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl">{{ $documentNumber }}</flux:heading>
                    <flux:badge size="sm" :color="$this->statusColor($transaction->type, $transaction->status)" inset="top bottom">
                        {{ $this->statusLabel($transaction->type, $transaction->status) }}
                    </flux:badge>
                </div>

                <flux:text>{{ $documentLabel }}</flux:text>
            </div>

            @if ($this->syncDate() !== null)
                <flux:text class="text-right">{{ __('Synced :date', ['date' => $this->syncDate()?->diffForHumans()]) }}</flux:text>
            @endif
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(15rem,1.1fr)_minmax(15rem,1.1fr)_minmax(0.75rem,0.25fr)_minmax(10rem,0.85fr)_minmax(10rem,0.85fr)]">
            <div class="min-w-0">
                <div class="mb-1 text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Bill To') }}</div>
                <address class="space-y-px not-italic text-[13px] leading-5 text-zinc-900 dark:text-zinc-100">
                    @forelse ($billToLines as $line)
                        <div class="truncate" title="{{ $line }}">{{ $line }}</div>
                    @empty
                        <div class="text-zinc-500 dark:text-zinc-400">-</div>
                    @endforelse
                </address>
            </div>

            <div class="min-w-0">
                <div class="mb-1 text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship To') }}</div>
                <address class="space-y-px not-italic text-[13px] leading-5 text-zinc-900 dark:text-zinc-100">
                    @forelse ($shipToLines as $line)
                        <div class="truncate" title="{{ $line }}">{{ $line }}</div>
                    @empty
                        <div class="text-zinc-500 dark:text-zinc-400">-</div>
                    @endforelse
                </address>
            </div>

            <div class="hidden xl:block"></div>

            <div class="self-start overflow-hidden rounded-md border border-zinc-200 xl:col-span-2 dark:border-white/10">
                <div class="bg-zinc-950 px-2.5 py-0.5 text-center text-xs font-medium uppercase text-white">{{ __('Summary') }}</div>

                <div class="grid grid-cols-3 divide-x divide-zinc-200 border-b border-zinc-200 bg-zinc-50 text-center dark:divide-white/10 dark:border-white/10 dark:bg-white/5">
                    <div class="px-2.5 py-1.5">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</div>
                        <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{{ $this->formattedMoney($totals['subtotal']) }}</div>
                    </div>
                    <div class="px-2.5 py-1.5">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Freight') }}</div>
                        <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{{ $this->formattedMoney($totals['freight']) }}</div>
                    </div>
                    <div class="px-2.5 py-1.5">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</div>
                        <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{{ $this->formattedMoney($totals['total']) }}</div>
                    </div>
                </div>

                @if ($totals['discount'] > 0)
                    <div class="border-b border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-center dark:border-white/10 dark:bg-white/5">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Discount') }}</span>
                        <span class="ms-2 text-zinc-700 dark:text-zinc-200">-{{ $this->formattedMoney($totals['discount']) }}</span>
                    </div>
                @endif

                <div class="grid grid-cols-3 divide-x divide-zinc-200 bg-zinc-50 text-center dark:divide-white/10 dark:bg-white/5">
                    <div class="px-2.5 py-1.5">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('PO Number') }}</div>
                        <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{{ $transaction->other_ref_num ?: '-' }}</div>
                    </div>
                    <div class="col-span-2 px-2.5 py-1.5">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Memo') }}</div>
                        <div class="mt-0.5 line-clamp-2 text-sm text-zinc-700 dark:text-zinc-200">{{ $transaction->memo ?: '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-y-3 border-y border-zinc-200 py-3 text-center sm:grid-cols-2 lg:grid-cols-7 dark:border-white/10">
            <div>
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ $numberLabel }}</div>
                <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $documentNumber }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</div>
                <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $this->statusLabel($transaction->type, $transaction->status) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Terms') }}</div>
                <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $transaction->terms_name ?: '-' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship Date') }}</div>
                <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $this->formattedDate($transaction->ship_date ?: $transaction->trandate) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship Via') }}</div>
                <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $transaction->ship_method_name ?: '-' }}</div>
            </div>
            <div class="lg:col-span-2">
                <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Tracking Number') }}</div>
                <div class="mt-1 break-words text-zinc-900 dark:text-zinc-100">
                    {{ $this->trackingNumbers->isEmpty() ? '-' : $this->trackingNumbers->join(', ') }}
                </div>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <flux:table class="{{ $lineTableClass }}">
                <flux:table.columns>
                    @if ($showLineItemNumbers)
                        <flux:table.column class="w-40">{{ __('Item No.') }}</flux:table.column>
                    @endif
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column align="end" class="w-14">{{ __('Qty') }}</flux:table.column>
                    @if ($showBackorderColumn)
                        <flux:table.column align="end" class="w-14">{{ __('B/O') }}</flux:table.column>
                    @endif
                    <flux:table.column align="end" class="w-24">{{ __('Price') }}</flux:table.column>
                    <flux:table.column align="end" class="w-24">{{ __('Amount') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->displayLines as $line)
                        @php($lineDescription = $this->lineDescription($line))
                        @php($lineItemNumber = $this->lineItemNumber($line))
                        <flux:table.row wire:key="transaction-line-{{ $line->id }}">
                            @if ($showLineItemNumbers)
                                <flux:table.cell>
                                    <span class="block max-w-full truncate" title="{{ $lineItemNumber ?: '-' }}">{{ $lineItemNumber ?: '-' }}</span>
                                </flux:table.cell>
                            @endif
                            <flux:table.cell class="min-w-0">
                                <span class="block max-w-full truncate" title="{{ $lineDescription }}">{{ $lineDescription }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $this->formattedQuantity($line->quantity) }}</flux:table.cell>
                            @if ($showBackorderColumn)
                                <flux:table.cell align="end">{{ $this->formattedQuantity($line->quantity_backordered) }}</flux:table.cell>
                            @endif
                            <flux:table.cell align="end">{{ $this->formattedMoney(abs((float) $line->rate)) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $this->formattedMoney(abs((float) $line->amount)) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell :colspan="$this->lineTableColumnCount()">
                                <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No line items are available for this transaction yet.') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endif
