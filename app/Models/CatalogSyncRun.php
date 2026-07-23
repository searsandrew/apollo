<?php

namespace App\Models;

use Database\Factories\CatalogSyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

#[Fillable([
    'uuid',
    'type',
    'status',
    'started_at',
    'finished_at',
    'cursor_value',
    'items_seen',
    'items_upserted',
    'aliases_upserted',
    'prices_upserted',
    'last_error',
    'payload',
])]
class CatalogSyncRun extends Model
{
    /** @use HasFactory<CatalogSyncRunFactory> */
    use HasFactory;

    public const string TYPE_FULL = 'full';

    public const string TYPE_INCREMENTAL = 'incremental';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_FINISHED = 'finished';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_seen' => 'integer',
            'items_upserted' => 'integer',
            'aliases_upserted' => 'integer',
            'prices_upserted' => 'integer',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CatalogSyncRun $syncRun): void {
            $syncRun->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function start(string $type, ?string $cursorValue = null, array $payload = []): self
    {
        return self::query()->create([
            'type' => $type,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'cursor_value' => $cursorValue,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}  $counts
     */
    public function markFinished(array $counts, ?string $cursorValue): void
    {
        $this->forceFill([
            'status' => self::STATUS_FINISHED,
            'finished_at' => now(),
            'cursor_value' => $cursorValue,
            'items_seen' => $counts['items_seen'],
            'items_upserted' => $counts['items_upserted'],
            'aliases_upserted' => $counts['aliases_upserted'],
            'prices_upserted' => $counts['prices_upserted'],
            'last_error' => null,
        ])->save();
    }

    public function markFailed(Throwable $exception): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'last_error' => $exception->getMessage(),
        ])->save();
    }
}
