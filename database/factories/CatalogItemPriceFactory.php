<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\CatalogItemPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItemPrice>
 */
class CatalogItemPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_item_id' => CatalogItem::factory(),
            'price_level' => 'Base Price',
            'minimum_quantity' => 0,
            'price' => fake()->randomFloat(2, 1, 250),
            'currency' => 'USD',
            'starts_at' => null,
            'ends_at' => null,
            'last_synced_at' => now(),
            'raw_payload' => [],
        ];
    }
}
