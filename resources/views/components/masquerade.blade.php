<?php

use App\Services\NetSuite\NetSuiteManagedCompanyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

new class extends Component
{
    private const int SEARCH_COMPANY_LIMIT = 15;

    private NetSuiteManagedCompanyService $managedCompanyService;

    /**
     * @var array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    public array $companies = [];

    public ?int $netSuiteId = null;

    public bool $hasSearchedCompanies = false;

    public string $companySearch = '';

    public function boot(NetSuiteManagedCompanyService $managedCompanyService): void
    {
        $this->managedCompanyService = $managedCompanyService;
    }

    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->netSuiteId = $user->netsuite_user_id === null ? null : (int) $user->netsuite_user_id;
    }

    public function selectCompany(int $netSuiteId): Redirector
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->netSuiteId = $netSuiteId;
            $user->setMeta('company_id', $netSuiteId);
            $user->save();
        }

        return redirect()->route('dashboard');
    }

    public function searchCompanies(string $search): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->companySearch = $this->normalizedCompanySearch($search);
        $this->hasSearchedCompanies = mb_strlen($this->companySearch) >= 2;

        if (! $this->hasSearchedCompanies) {
            $this->companies = [];

            return;
        }

        $this->companies = $this->managedCompanyService->searchForUser(
            $user,
            $this->companySearch,
            self::SEARCH_COMPANY_LIMIT,
        );
    }

    private function normalizedCompanySearch(string $search): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $search));
    }
};
?>

<flux:tooltip :content="__('Masquerade')" position="bottom" gap="10">
    <flux:dropdown
        position="bottom"
        align="end"
        x-data="{
            open: false,
            search: '',
            activeIndex: -1,
            focusSearch() {
                this.$nextTick(() => setTimeout(() => this.$refs.companySearch?.focus(), 50))
            },
            toggleOpen() {
                this.open = ! this.open

                if (this.open) {
                    this.focusSearch()
                }
            },
            searchCompanies() {
                this.activeIndex = -1
                this.$wire.searchCompanies(this.search)
            },
            companyItems() {
                return Array.from(this.$root.querySelectorAll('[data-masquerade-company]'))
            },
            moveActive(direction) {
                const items = this.companyItems()

                if (items.length === 0) {
                    this.activeIndex = -1

                    return
                }

                if (this.activeIndex === -1) {
                    this.activeIndex = direction > 0 ? 0 : items.length - 1
                } else {
                    this.activeIndex = (this.activeIndex + direction + items.length) % items.length
                }

                this.$nextTick(() => items[this.activeIndex]?.scrollIntoView({ block: 'nearest' }))
            },
            selectActive() {
                this.companyItems()[this.activeIndex]?.click()
            },
        }"
        x-on:click.outside="open = false"
        x-on:keydown.escape.window="open = false"
        x-on:keydown.window="
            if (open) {
                if ($event.key === 'ArrowDown') {
                    $event.preventDefault()
                    moveActive(1)
                }

                if ($event.key === 'ArrowUp') {
                    $event.preventDefault()
                    moveActive(-1)
                }

                if ($event.key === 'Enter') {
                    $event.preventDefault()
                    selectActive()
                }
            }
        "
    >
        <flux:button
            class="h-10 cursor-pointer max-lg:hidden [&>div>svg]:size-5"
            x-bind:class="open ? 'bg-zinc-100 text-zinc-900 dark:bg-white/10 dark:text-white' : ''"
            variant="subtle"
            icon="fa-masks-theater"
            :label="__('Masquerade')"
            x-on:click="toggleOpen()"
        />
        <flux:menu
            class="shrink-0 overflow-hidden origin-top-right transition-[max-height,opacity,transform] duration-200 ease-out"
            style="width: min(20rem, calc(100vw - 1rem));"
        >
            <div class="p-1.5" wire:ignore>
                <flux:input
                    x-ref="companySearch"
                    x-model="search"
                    x-on:input.debounce.350ms="searchCompanies()"
                    icon="magnifying-glass"
                    clearable
                    loading="searchCompanies"
                    :placeholder="__('Search companies')"
                />
            </div>

            <div
                class="overflow-y-auto transition-all duration-200 ease-out"
                x-bind:class="search.trim().length >= 2 ? 'max-h-80 opacity-100' : 'max-h-0 opacity-0'"
            >
                <div
                    wire:loading.flex
                    wire:target="searchCompanies"
                    class="items-center gap-2 px-2 py-2 text-sm font-medium text-zinc-500 dark:text-zinc-300"
                >
                    <flux:icon.loading variant="mini" class="text-zinc-400"/>
                    {{ __('Searching...') }}
                </div>

                <div wire:loading.remove wire:target="searchCompanies" class="pt-1">
                    @if ($hasSearchedCompanies && $companies === [])
                        <flux:menu.item disabled>{{ __('No companies found') }}</flux:menu.item>
                    @endif

                    @foreach ($companies as $company)
                        <flux:menu.item
                            class="relative w-full transition-colors"
                            data-masquerade-company
                            x-bind:data-active="activeIndex === {{ $loop->index }} ? '' : null"
                            x-bind:class="activeIndex === {{ $loop->index }} ? 'bg-sky-50 text-sky-950 dark:bg-sky-400/20 dark:text-sky-50' : ''"
                            x-on:mouseenter="activeIndex = {{ $loop->index }}"
                            wire:key="masquerade-company-{{ $company['id'] }}"
                            wire:click="selectCompany({{ $company['id'] }})"
                        >
                            <span
                                aria-hidden="true"
                                class="absolute inset-y-1 left-1 w-1 rounded-full bg-sky-500 transition-opacity"
                                x-bind:class="activeIndex === {{ $loop->index }} ? 'opacity-100' : 'opacity-0'"
                            ></span>

                            <div class="flex w-full min-w-0 items-center gap-2 ps-2">
                                @if ($company['account_number'] !== null)
                                    <flux:badge class="max-w-24 shrink-0 truncate" size="sm" rounded>
                                        {{ $company['account_number'] }}
                                    </flux:badge>
                                @endif

                                <span class="min-w-0 flex-1 truncate">{{ $company['name'] }}</span>
                            </div>
                        </flux:menu.item>
                    @endforeach
                </div>
            </div>
        </flux:menu>
    </flux:dropdown>
</flux:tooltip>
