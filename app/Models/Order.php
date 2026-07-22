<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'uuid',
    'netsuite_company_id',
    'created_by_user_id',
    'status',
    'origin',
    'po_number',
    'remarks',
    'billing_address_ref_id',
    'shipping_address_ref_id',
    'netsuite_sales_order_id',
    'netsuite_sales_order_number',
    'submitted_at',
    'synced_at',
    'part_number',
    'quantity',
    'position',
])]
class Order extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_SUBMITTED = 'submitted';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    protected $auditInclude = [
        'netsuite_company_id',
        'created_by_user_id',
        'status',
        'origin',
        'po_number',
        'remarks',
        'billing_address_ref_id',
        'shipping_address_ref_id',
        'netsuite_sales_order_id',
        'netsuite_sales_order_number',
        'submitted_at',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'netsuite_company_id' => 'integer',
            'created_by_user_id' => 'integer',
            'billing_address_ref_id' => 'integer',
            'shipping_address_ref_id' => 'integer',
            'netsuite_sales_order_id' => 'integer',
            'submitted_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function companySummary(): BelongsTo
    {
        return $this->belongsTo(CompanySummary::class, 'netsuite_company_id', 'netsuite_company_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * @return array<int, string>
     */
    public function generateTags(): array
    {
        return [
            'order',
            'company:'.$this->netsuite_company_id,
        ];
    }
}
