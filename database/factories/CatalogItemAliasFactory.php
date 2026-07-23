<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\CatalogItemAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItemAlias>
 */
class CatalogItemAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $alias = strtoupper(fake()->bothify('??##??###'));

        return [
            'catalog_item_id' => CatalogItem::factory(),
            'alias' => $alias,
            'normalized_alias' => preg_replace('/[\s._-]+/', '', $alias) ?? $alias,
            'type' => CatalogItemAlias::TYPE_CROSS_REFERENCE,
            'source' => 'test',
            'confidence' => 90,
            'last_synced_at' => now(),
            'raw_payload' => [],
        ];
    }
}
