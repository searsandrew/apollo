<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_snapshot_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('netsuite_company_id')->unique();
            $table->string('account_number')->nullable()->index();
            $table->string('company_name')->nullable()->index();
            $table->string('entity_id')->nullable()->index();
            $table->unsignedBigInteger('sales_rep_id')->nullable()->index();
            $table->date('last_transaction_date')->nullable()->index();
            $table->decimal('ytd_sales', 15, 2)->default(0);
            $table->decimal('trailing_12_sales', 15, 2)->default(0);
            $table->decimal('open_order_total', 15, 2)->default(0);
            $table->decimal('invoice_total', 15, 2)->default(0);
            $table->decimal('credit_memo_total', 15, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->json('totals_by_type')->nullable();
            $table->timestamp('snapshot_synced_at')->nullable()->index();
            $table->timestamp('summary_synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_summaries');
    }
};
