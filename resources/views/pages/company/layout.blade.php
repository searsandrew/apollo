@props([
    'company',
    'companyName' => null,
    'accountNumber' => null,
    'loadingCompanyHeader' => true,
])

<div class="w-full">
    <div class="relative mb-6 w-full">
        @if ($loadingCompanyHeader)
            <flux:heading size="xl" level="1">
                <flux:skeleton.line animate="shimmer" class="h-10 w-full sm:w-1/3" />
            </flux:heading>

            <flux:subheading size="lg" class="mb-6">
                <flux:skeleton.line animate="shimmer" class="w-1/2 sm:w-1/5" />
            </flux:subheading>
        @else
            <flux:heading size="xl" level="1">{{ $companyName }}</flux:heading>
            <flux:subheading size="lg" class="mb-6">{{ $accountNumber }}</flux:subheading>
        @endif

        <flux:separator variant="subtle" />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-55">
            <flux:navlist aria-label="{{ __('Company') }}">
                <flux:navlist.item :href="route('company.show', $company)" :current="request()->routeIs('company.show')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Purchases') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Invoices') }}</flux:navlist.item>
                <flux:navlist.group :heading="__('Marketing')" expandable :expanded="false">
                    <flux:navlist.item href="#">{{ __('Landing Page') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Fliers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Emails') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Customers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Settings') }}</flux:navlist.item>
                </flux:navlist.group>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="mt-5 w-full max-w-lg">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
