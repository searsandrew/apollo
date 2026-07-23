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
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('netsuite_item_id')->nullable()->unique();
            $table->string('item_number')->unique();
            $table->string('normalized_item_number')->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_inactive')->default(false)->index();
            $table->boolean('is_discontinued')->default(false)->index();
            $table->unsignedInteger('multiple')->nullable();
            $table->integer('available_quantity')->nullable();
            $table->string('availability_status')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'normalized_item_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
