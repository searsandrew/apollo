<?php

use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Livewire\Component;

new class extends Component
{
    public int $netsuiteCompanyId;

    /**
     * @var array<string, mixed>
     */
    public array $debug = [];

    private CompanySnapshotSyncer $snapshotSyncer;

    public function boot(CompanySnapshotSyncer $snapshotSyncer): void
    {
        $this->snapshotSyncer = $snapshotSyncer;
    }

    public function mount(string $company): void
    {
        $this->netsuiteCompanyId = (int) $company;

        abort_if($this->netsuiteCompanyId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);
        $this->snapshotSyncer->queueRefreshIfStale($snapshot);

        $this->debug = $this->snapshotSyncer->debugSnapshot($snapshot);
    }
};
?>

<section class="w-full">
    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>
    <x-pages::company.layout :company="$netsuiteCompanyId">
        <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-50">{{ json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </x-pages::company.layout>
</section>
