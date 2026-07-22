<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use App\Services\CompanySnapshots\CompanySnapshotTransactionDetailRepository;
use Livewire\Component;

new class extends Component {
    public int $netsuiteCompanyId;

    public int $snapshotId;

    public int $transactionId;

    private CompanySnapshotSyncer $snapshotSyncer;

    private CompanySnapshotTransactionDetailRepository $transactionRepository;

    public function boot(CompanySnapshotSyncer $snapshotSyncer, CompanySnapshotTransactionDetailRepository $transactionRepository): void
    {
        $this->snapshotSyncer = $snapshotSyncer;
        $this->transactionRepository = $transactionRepository;
    }

    public function mount(string $company, string $transaction): void
    {
        $this->netsuiteCompanyId = (int) $company;
        abort_if($this->netsuiteCompanyId <= 0, 404);

        $this->transactionId = (int) $transaction;
        abort_if($this->transactionId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);

        $this->snapshotId = $snapshot->id;
        $this->snapshotSyncer->queueRefreshIfStale($snapshot, transactionStaleDays: 1);
    }

    public function render()
    {
        return $this->view()->title($this->pageTitle('Sales Order', ['SalesOrd']));
    }

    /**
     * @param  array<int, string>  $types
     */
    private function pageTitle(string $documentLabel, array $types): string
    {
        $snapshot = CompanySnapshot::query()->findOrFail($this->snapshotId);
        $documentNumber = $this->transactionRepository->documentNumber($snapshot, $this->transactionId, $types);

        return filled($documentNumber) ? $documentLabel.' '.$documentNumber : $documentLabel;
    }
};
?>

<section class="w-full">
    <x-pages::company.layout :company="$netsuiteCompanyId">
        <livewire:components::company-transaction-detail
            :snapshot-id="$snapshotId"
            :transaction-id="$transactionId"
            :types="['SalesOrd']"
            document-label="{{ __('Sales Order') }}"
            number-label="{{ __('Order #') }}"
        />
    </x-pages::company.layout>
</section>
