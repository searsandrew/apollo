<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Searsandrew\BriarRose\Facades\BriarRose;

new class extends Component
{
    private const int CLOSED_WON_CUSTOMER_STATUS_ID = 13;

    private const int CUSTOMER_PAGE_LIMIT = 1000;

    /**
     * @var array<int, array{id: int, name: string, email: string|null}>
     */
    public array $customers = [];

    public ?int $netSuiteId = null;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->netSuiteId = $user->netsuite_user_id === null ? null : (int) $user->netsuite_user_id;
        $this->customers = $this->customersForManagedSalesReps($user->getMeta('netsuite_managed_ids'));
    }

    public function updatedNetSuiteId(): RedirectResponse
    {
        Auth::user()->netsuite_user_id = $this->netSuiteId;
        Auth::user()->save();

        return redirect()->route('dashboard');
    }

    /**
     * @return array<int, array{id: int, name: string, email: string|null}>
     */
    private function customersForManagedSalesReps(mixed $managedSalesRepIds): array
    {
        $salesRepIds = $this->normalizedManagedSalesRepIds($managedSalesRepIds);

        if ($salesRepIds === []) {
            return [];
        }

        $salesRepIdsSql = implode(', ', $salesRepIds);
        $closedWonCustomerStatusId = self::CLOSED_WON_CUSTOMER_STATUS_ID;
        $sql = <<<SQL
            SELECT
                id,
                entityid,
                companyname,
                email
            FROM customer
            WHERE isinactive = 'F'
                AND entitystatus = {$closedWonCustomerStatusId}
                AND salesrep IN ({$salesRepIdsSql})
            ORDER BY companyname ASC, entityid ASC
        SQL;

        $customers = [];
        $offset = 0;

        try {
            do {
                $page = BriarRose::rest()->suiteql()->query($sql, [
                    'limit' => self::CUSTOMER_PAGE_LIMIT,
                    'offset' => $offset,
                ])->throw()->json();

                foreach ($page['items'] ?? [] as $customer) {
                    $customers[] = [
                        'id' => (int) $customer['id'],
                        'name' => $customer['companyname'] ?: $customer['entityid'] ?: 'Customer '.$customer['id'],
                        'email' => $customer['email'] ?? null,
                    ];
                }

                $offset += self::CUSTOMER_PAGE_LIMIT;
            } while (($page['hasMore'] ?? false) === true);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return $customers;
    }

    /**
     * @return array<int, int>
     */
    private function normalizedManagedSalesRepIds(mixed $managedSalesRepIds): array
    {
        if (! is_array($managedSalesRepIds)) {
            return [];
        }

        return collect($managedSalesRepIds)
            ->map(fn (mixed $salesRepId): int => (int) $salesRepId)
            ->filter(fn (int $salesRepId): bool => $salesRepId > 0)
            ->unique()
            ->values()
            ->all();
    }
};
?>

<flux:dropdown>
    <flux:button
        class="h-10 cursor-pointer max-lg:hidden [&>div>svg]:size-5"
        variant="subtle"
        icon="fa-masks-theater"
        :label="__('Masquerade')"
    />
    <flux:menu>
        @if ($customers === [])
            <flux:menu.item disabled>{{ __('No customers found') }}</flux:menu.item>
        @else
            <flux:menu.radio.group wire:model.live="netSuiteId">
                @foreach ($customers as $customer)
                    <flux:menu.radio wire:key="masquerade-customer-{{ $customer['id'] }}" value="{{ $customer['id'] }}">
                        {{ $customer['name'] }}
                    </flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>
        @endif
    </flux:menu>
</flux:dropdown>
