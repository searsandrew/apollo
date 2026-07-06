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
        Schema::create('company_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('netsuite_company_id')->unique();
            $table->string('connection_name')->unique();
            $table->string('database_path')->unique();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamp('last_viewed_at')->nullable()->index();
            $table->timestamp('meta_synced_at')->nullable()->index();
            $table->timestamp('transactions_synced_at')->nullable()->index();
            $table->timestamp('summary_synced_at')->nullable()->index();
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_snapshots');
    }
};
