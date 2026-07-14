<?php

namespace App\Models;

use Database\Factories\CompanySummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_snapshot_id',
    'netsuite_company_id',
    'account_number',
    'company_name',
    'entity_id',
    'terms',
    'sales_rep_id',
    'last_transaction_date',
    'ytd_sales',
    'trailing_12_sales',
    'open_order_total',
    'invoice_total',
    'credit_memo_total',
    'transaction_count',
    'totals_by_type',
    'snapshot_synced_at',
    'summary_synced_at',
])]
class CompanySummary extends Model
{
    /** @use HasFactory<CompanySummaryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'netsuite_company_id' => 'integer',
            'sales_rep_id' => 'integer',
            'last_transaction_date' => 'date',
            'ytd_sales' => 'decimal:2',
            'trailing_12_sales' => 'decimal:2',
            'open_order_total' => 'decimal:2',
            'invoice_total' => 'decimal:2',
            'credit_memo_total' => 'decimal:2',
            'transaction_count' => 'integer',
            'totals_by_type' => 'array',
            'snapshot_synced_at' => 'datetime',
            'summary_synced_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CompanySnapshot::class, 'company_snapshot_id');
    }
}
