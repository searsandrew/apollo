<?php

namespace App\Models;

use Database\Factories\CompanySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'netsuite_company_id',
    'connection_name',
    'database_path',
    'status',
    'schema_version',
    'last_viewed_at',
    'meta_synced_at',
    'transactions_synced_at',
    'summary_synced_at',
    'sync_started_at',
    'sync_finished_at',
    'last_error',
])]
class CompanySnapshot extends Model
{
    /** @use HasFactory<CompanySnapshotFactory> */
    use HasFactory;

    public const int SCHEMA_VERSION = 1;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_SYNCING_META = 'syncing_meta';

    public const string STATUS_SYNCING_TRANSACTIONS = 'syncing_transactions';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'netsuite_company_id' => 'integer',
            'schema_version' => 'integer',
            'last_viewed_at' => 'datetime',
            'meta_synced_at' => 'datetime',
            'transactions_synced_at' => 'datetime',
            'summary_synced_at' => 'datetime',
            'sync_started_at' => 'datetime',
            'sync_finished_at' => 'datetime',
        ];
    }

    public function summary(): HasOne
    {
        return $this->hasOne(CompanySummary::class);
    }

    public function isMetaStale(int $days = 1): bool
    {
        return $this->meta_synced_at === null
            || $this->meta_synced_at->lt(Carbon::now()->subDays($days));
    }

    public function areTransactionsStale(int $days = 7): bool
    {
        return $this->transactions_synced_at === null
            || $this->transactions_synced_at->lt(Carbon::now()->subDays($days));
    }

    public function getRouteKeyName(): string
    {
        return 'netsuite_company_id';
    }
}
