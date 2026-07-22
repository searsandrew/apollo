<?php

use App\Models\CompanySummary;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Orders')] class extends Component {
    use WithPagination;

    private const string PAGE_NAME = 'orders-page';

    #[Computed]
    public function selectedCompany(): ?CompanySummary
    {
        $netsuiteCompanyId = (int) Auth::user()->getMeta('company_id', 0);

        if ($netsuiteCompanyId <= 0) {
            return null;
        }

        return CompanySummary::query()
            ->where('netsuite_company_id', $netsuiteCompanyId)
            ->first();
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $query = Order::query()
            ->with('companySummary')
            ->withCount('lines')
            ->where('created_by_user_id', Auth::id())
            ->latest('updated_at');

        $netsuiteCompanyId = (int) Auth::user()->getMeta('company_id', 0);

        if ($netsuiteCompanyId > 0) {
            $query->where('netsuite_company_id', $netsuiteCompanyId);
        }

        return $query->paginate(15, pageName: self::PAGE_NAME);
    }
};
?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Orders') }}</flux:heading>
                <flux:text>
                    @if ($this->selectedCompany)
                        {{ __('Drafts and submitted orders for :company.', ['company' => $this->selectedCompany->company_name]) }}
                    @else
                        {{ __('Select a company before starting a new order.') }}
                    @endif
                </flux:text>
            </div>

            @can('create order')
                <form method="POST" action="{{ route('order.create') }}">
                    @csrf
                    <flux:button type="submit" variant="primary" icon="plus">
                        {{ __('New Order') }}
                    </flux:button>
                </form>
            @endcan
        </div>

        @error('company')
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Company Required') }}</flux:callout.heading>
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        <flux:table class="w-full" :paginate="$this->orders">
            <flux:table.columns>
                <flux:table.column>{{ __('Order') }}</flux:table.column>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('PO Number') }}</flux:table.column>
                <flux:table.column>{{ __('Lines') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Updated') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->orders as $order)
                    <flux:table.row :href="route('order.show', $order)" wire:navigate>
                        <flux:table.cell>
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ __('Order #:id', ['id' => $order->id]) }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="max-w-72 truncate" title="{{ $order->companySummary?->company_name ?? $order->netsuite_company_id }}">
                                {{ $order->companySummary?->company_name ?? __('Company #:id', ['id' => $order->netsuite_company_id]) }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ filled($order->po_number) ? $order->po_number : '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $order->lines_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="amber" inset="top bottom">{{ __(str($order->status)->headline()->toString()) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->updated_at->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="py-10 text-center">
                                <flux:heading size="sm">{{ __('No orders yet') }}</flux:heading>
                                <flux:text>{{ __('Start a draft and Apollo will save it while it is being built.') }}</flux:text>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
