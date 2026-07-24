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
            $table->boolean('is_online')->default(false)->after('is_discontinued')->index();
            $table->boolean('custitem6')->default(false)->after('is_online')->index();
            $table->softDeletes();

            $table->index(['is_online', 'custitem6', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['is_online', 'custitem6', 'status']);
            $table->dropIndex(['is_online']);
            $table->dropIndex(['custitem6']);
            $table->dropColumn(['is_online', 'custitem6']);
            $table->dropSoftDeletes();
        });
    }
};
