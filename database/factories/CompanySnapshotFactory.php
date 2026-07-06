<?php

namespace Database\Factories;

use App\Models\CompanySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySnapshot>
 */
class CompanySnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $netsuiteCompanyId = fake()->unique()->numberBetween(100, 999_999);

        return [
            'netsuite_company_id' => $netsuiteCompanyId,
            'connection_name' => 'company_'.$netsuiteCompanyId,
            'database_path' => storage_path('framework/testing/company-snapshots/company_'.$netsuiteCompanyId.'.sqlite'),
            'status' => CompanySnapshot::STATUS_PENDING,
            'schema_version' => CompanySnapshot::SCHEMA_VERSION,
            'last_viewed_at' => null,
            'meta_synced_at' => null,
            'transactions_synced_at' => null,
            'summary_synced_at' => null,
            'sync_started_at' => null,
            'sync_finished_at' => null,
            'last_error' => null,
        ];
    }
}
