<?php

use App\Models\CompanySnapshot;
use App\Models\CompanySummary;
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

        $this->debug = $this->snapshotSyncer->debugSnapshot($snapshot);
    }

    #[Computed]
    public function snapshot(): ?CompanySnapshot
    {
        return CompanySnapshot::query()->find($this->snapshotId);
    }

    #[Computed]
    public function summary(): ?CompanySummary
    {
        return CompanySummary::query()
            ->where('company_snapshot_id', $this->snapshotId)
            ->first();
    }

    #[Computed]
    public function companyHeaderReady(): bool
    {
        return filled($this->summary?->company_name)
            && filled($this->summary?->account_number);
    }
};
?>

<section class="w-full">
    <x-pages::company.layout
        :company="$netsuiteCompanyId"
        :company-name="$this->summary?->company_name"
        :account-number="$this->summary?->account_number"
        :loading-company-header="! $this->companyHeaderReady"
    >
        <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-50">{{ json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </x-pages::company.layout>
</section>
