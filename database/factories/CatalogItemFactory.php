<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatalogItem>
 */
class CatalogItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemNumber = strtoupper(fake()->bothify('??##??###'));

        return [
            'netsuite_item_id' => fake()->unique()->numberBetween(1000, 999999),
            'item_number' => $itemNumber,
            'normalized_item_number' => preg_replace('/[\s._-]+/', '', $itemNumber) ?? $itemNumber,
            'display_name' => Str::headline(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'status' => CatalogItem::STATUS_ACTIVE,
            'is_inactive' => false,
            'is_discontinued' => false,
            'multiple' => 1,
            'available_quantity' => fake()->numberBetween(0, 500),
            'availability_status' => 'in_stock',
            'last_synced_at' => now(),
            'raw_payload' => [],
        ];
    }
}
