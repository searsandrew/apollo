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
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['is_online', 'status']);
            $table->dropIndex(['status', 'normalized_item_number']);
            $table->dropIndex(['status']);
            $table->dropColumn('status');

            $table->index(['is_online', 'normalized_item_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['is_online', 'normalized_item_number']);
            $table->string('status')->default('active')->after('description')->index();
            $table->index(['status', 'normalized_item_number']);
            $table->index(['is_online', 'status']);
        });
    }
};
