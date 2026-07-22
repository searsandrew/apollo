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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('netsuite_company_id')->index();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->string('origin')->default('web')->index();
            $table->string('po_number')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('billing_address_ref_id')->nullable();
            $table->unsignedBigInteger('shipping_address_ref_id')->nullable();
            $table->unsignedBigInteger('netsuite_sales_order_id')->nullable()->index();
            $table->string('netsuite_sales_order_number')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['netsuite_company_id', 'status']);
            $table->index(['created_by_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
