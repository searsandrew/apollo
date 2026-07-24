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
            $table->dropIndex(['is_online', 'custitem6', 'status']);
            $table->dropIndex(['custitem6']);
            $table->dropColumn('custitem6');
            $table->index(['is_online', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['is_online', 'status']);
            $table->boolean('custitem6')->default(false)->after('is_online')->index();
            $table->index(['is_online', 'custitem6', 'status']);
        });
    }
};
