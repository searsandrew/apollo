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
        Schema::create('catalog_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->string('price_level')->default('Base Price')->index();
            $table->unsignedInteger('minimum_quantity')->default(0);
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'price_level', 'minimum_quantity', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_item_prices');
    }
};
