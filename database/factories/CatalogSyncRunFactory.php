<?php

namespace Database\Factories;

use App\Models\CatalogSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatalogSyncRun>
 */
class CatalogSyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => CatalogSyncRun::TYPE_INCREMENTAL,
            'status' => CatalogSyncRun::STATUS_FINISHED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'cursor_value' => now()->toDateTimeString(),
            'items_seen' => 1,
            'items_upserted' => 1,
            'aliases_upserted' => 0,
            'prices_upserted' => 0,
            'last_error' => null,
            'payload' => [],
        ];
    }
}
