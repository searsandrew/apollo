@props([
    'company' => request()->route('company'),
])

@php
    $summary = \App\Models\CompanySummary::where('netsuite_company_id', $company)->first();
    $currentPath = trim(parse_url(request()->headers->get('referer') ?: request()->getRequestUri(), PHP_URL_PATH) ?: '', '/');
    $profilePath = trim(route('company.show', $company, false), '/');
    $purchaseOrdersPath = trim(route('company.purchase-orders', $company, false), '/');
    $salesOrdersPath = trim(route('company.sales-orders.index', $company, false), '/');
    $invoicesPath = trim(route('company.invoices.index', $company, false), '/');
    $creditMemosPath = dirname(trim(route('company.credit-memos.show', [$company, 0], false), '/'));
    $isCurrentPath = fn (string $path): bool => $currentPath === $path;
    $isCurrentSection = fn (string $path): bool => $currentPath === $path || str_starts_with($currentPath, $path.'/');
@endphp

<div class="w-full">
    <livewire:components::company-name :company="$company" />
    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-55">
            <flux:navlist aria-label="{{ __('Company') }}">
                <flux:navlist.item icon="building-storefront" :href="route('company.show', $company)" :current="$isCurrentPath($profilePath)" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-list" :href="route('company.purchase-orders', $company)" :current="$isCurrentSection($purchaseOrdersPath)" wire:navigate>{{ __('Purchase Orders') }}</flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-check" :href="route('company.sales-orders.index', $company)" :current="$isCurrentSection($salesOrdersPath)" wire:navigate>{{ __('Sales Orders') }}</flux:navlist.item>
                <flux:navlist.item :icon="($summary?->terms == 'Credit Card at Time of Purchase' ? 'credit-card' : 'banknotes')" :href="route('company.invoices.index', $company)" :current="$isCurrentSection($invoicesPath) || $isCurrentSection($creditMemosPath)" wire:navigate>{{ __('Invoices') }}</flux:navlist.item>
                <flux:navlist.group :heading="__('Marketing')" expandable :expanded="false">
                    <flux:navlist.item href="#">{{ __('Landing Page') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Fliers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Emails') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Customers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Settings') }}</flux:navlist.item>
                </flux:navlist.group>
                <flux:navlist.item icon="user-group" :href="route('appearance.edit')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item icon="cog-8-tooth" :href="route('appearance.edit')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="flex flex-col sm:flex-row">
                <div @class(['mt-5 w-full', 'sm:w-2/3 mr-5' => isset($sidebar)])>
                    {{ $slot }}
                </div>
                @isset($sidebar)
                    <sidebar class="order-first sm:order-last w-full sm:max-w-1/3">
                        {{ $sidebar }}
                    </sidebar>
                @endisset
            </div>
        </div>
    </div>
</div>
