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
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->after('order_id')
                ->constrained('catalog_items')
                ->nullOnDelete();
            $table->string('resolved_part_number')->nullable()->after('part_number');
            $table->string('resolution_status')->default('unresolved')->after('resolved_part_number')->index();
            $table->string('resolution_type')->nullable()->after('resolution_status')->index();
            $table->timestamp('resolved_at')->nullable()->after('resolution_type');

            $table->index(['catalog_item_id', 'resolution_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
            $table->dropIndex(['catalog_item_id', 'resolution_status']);
            $table->dropColumn([
                'catalog_item_id',
                'resolved_part_number',
                'resolution_status',
                'resolution_type',
                'resolved_at',
            ]);
        });
    }
};
