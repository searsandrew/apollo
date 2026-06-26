<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    @include('partials.head')
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" class="mr-5" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:navbar.item>
                @can('view order')
                    <flux:navbar.item :href="route('dashboard')" :current="request()->routeIs('order.*')" wire:navigate>
                        {{ __('Orders') }}
                    </flux:navbar.item>
                @endcan
                @can('view invoice')
                    <flux:navbar.item :href="route('dashboard')" :current="request()->routeIs('invoice.*')" wire:navigate>
                        {{ __('Invoices') }}
                    </flux:navbar.item>
                @endcan
                @if((Auth::user()->can('view customer') && Auth::user()->getMeta('hasLandingPage', false)) || Auth::user()->canAny(['view masquerade', 'view company', 'view user', 'view permission','edit setting']))
                    <flux:dropdown>
                        <flux:navbar.item icon:trailing="chevron-down" :current="request()->routeIs('admin.*')" wire:navigate>
                            {{ __('Admin') }}
                        </flux:navbar.item>
                        <flux:navmenu>
                            @can('view masquerade')
                                <flux:navmenu.item href="#">{{ __('Orders') }}</flux:navmenu.item>
                            @endcan
                            @canany(['view company', 'view customer', 'view user'])
                                <flux:menu.group :heading="__('Lists')">
                                    @can('view company')
                                        <flux:navmenu.item href="#">{{ __('Companies') }}</flux:navmenu.item>
                                    @endcan
                                    @can('view customer')
                                        @if(Auth::user()->getMeta('hasLandingPage', false))
                                            <flux:navmenu.item href="#">{{ __('Customers') }}</flux:navmenu.item>
                                        @endif
                                    @endcan
                                    @can('view lead')
                                        <flux:navmenu.item href="#">{{ __('Leads') }}</flux:navmenu.item>
                                    @endcan
                                    @can('view user')
                                        <flux:navmenu.item href="#">{{ __('Users') }}</flux:navmenu.item>
                                    @endcan
                                </flux:menu.group>
                            @endcanany
                            @canany(['view permission','edit setting'])
                                <flux:menu.group :heading="__('Settings')">
                                    @can('view permission')
                                        <flux:navmenu.item href="#">{{ __('Permissions') }}</flux:navmenu.item>
                                    @endcan
                                    @can('edit setting')
                                        <flux:navmenu.item href="#">{{ __('Rec Overrides') }}</flux:navmenu.item>
                                        <flux:navmenu.item href="#">{{ __('App Settings') }}</flux:navmenu.item>
                                    @endcan
                                </flux:menu.group>
                            @endcanany
                        </flux:navmenu>
                    </flux:dropdown>
                @endif
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">
                @can('view item')
                    @livewire('search')
                @endcan
                @can('create order')
                    <flux:tooltip :content="__('Create Order')" position="bottom">
                        <flux:navbar.item
                            class="h-10 max-lg:hidden [&>div>svg]:size-5"
                            icon="fa-cart-plus"
                            href="#"
                            :label="__('Create Order')"
                        />
                    </flux:tooltip>
                @endcan
                @can('view instruction')
                    <flux:tooltip :content="__('Documentation')" position="bottom">
                        <flux:navbar.item
                            class="h-10 max-lg:hidden [&>div>svg]:size-5"
                            icon="book-open-text"
                            href="#"
                            target="_blank"
                            :label="__('Documentation')"
                        />
                    </flux:tooltip>
                @endcan
                @can('view masquerade')
                    @livewire('masquerade')
                @endcan
            </flux:navbar>
            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard')  }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
        </flux:sidebar>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
