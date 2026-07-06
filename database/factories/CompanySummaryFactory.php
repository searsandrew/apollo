<?php

namespace Database\Factories;

use App\Models\CompanySnapshot;
use App\Models\CompanySummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySummary>
 */
class CompanySummaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $snapshot = CompanySnapshot::factory()->create();

        return [
            'company_snapshot_id' => $snapshot->id,
            'netsuite_company_id' => $snapshot->netsuite_company_id,
            'account_number' => fake()->bothify('C-####'),
            'company_name' => fake()->company(),
            'entity_id' => fake()->bothify('COMP-####'),
            'sales_rep_id' => fake()->numberBetween(1, 9999),
            'last_transaction_date' => fake()->date(),
            'ytd_sales' => fake()->randomFloat(2, 0, 100000),
            'trailing_12_sales' => fake()->randomFloat(2, 0, 250000),
            'open_order_total' => fake()->randomFloat(2, 0, 50000),
            'invoice_total' => fake()->randomFloat(2, 0, 100000),
            'credit_memo_total' => fake()->randomFloat(2, 0, 10000),
            'transaction_count' => fake()->numberBetween(0, 500),
            'totals_by_type' => [],
            'snapshot_synced_at' => now(),
            'summary_synced_at' => now(),
        ];
    }
}
