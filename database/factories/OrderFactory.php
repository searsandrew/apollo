<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'netsuite_company_id' => fake()->numberBetween(100, 9999),
            'created_by_user_id' => User::factory(),
            'status' => Order::STATUS_DRAFT,
            'origin' => 'web',
            'po_number' => fake()->optional()->bothify('PO-####'),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
