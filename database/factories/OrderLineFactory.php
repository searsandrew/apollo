<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'catalog_item_id' => null,
            'part_number' => fake()->bothify('??###??'),
            'resolved_part_number' => null,
            'resolution_status' => 'unresolved',
            'resolution_type' => null,
            'resolved_at' => null,
            'description' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 12),
            'unit_price' => null,
            'amount' => null,
            'availability_status' => null,
            'position' => fake()->numberBetween(1, 20),
        ];
    }
}
