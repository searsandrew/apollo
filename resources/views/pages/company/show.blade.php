<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $netsuiteCompanyId;
    public int $snapshotId;

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
        $this->netsuiteCompanyId = (int)$company;

        abort_if($this->netsuiteCompanyId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);

        $this->snapshotId = $snapshot->id;
        $this->snapshotSyncer->queueRefreshIfStale($snapshot);
    }

    #[Computed]
    public function snapshot(): CompanySnapshot
    {
        return CompanySnapshot::query()->findOrFail($this->snapshotId);
    }

    #[Computed]
    public function debugSnapshot(): array
    {
        return $this->snapshotSyncer->debugSnapshot($this->snapshot);
    }
};
?>

<section class="w-full" wire:poll.visible.1500ms>
    <x-pages::company.layout :company="$netsuiteCompanyId" current="profile">
        <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-50">{{ json_encode($this->debugSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </x-pages::company.layout>
</section>
