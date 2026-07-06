<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CompanySnapshotDatabaseManager
{
    public function connectionNameFor(int $netsuiteCompanyId): string
    {
        return 'company_'.$netsuiteCompanyId;
    }

    public function databasePathFor(int $netsuiteCompanyId): string
    {
        return $this->basePath().DIRECTORY_SEPARATOR.$this->connectionNameFor($netsuiteCompanyId).'.sqlite';
    }

    public function ensureDatabase(CompanySnapshot $snapshot): Connection
    {
        $this->ensureFileExists($snapshot->database_path);
        $this->configureConnection($snapshot);
        $this->ensureSchema($snapshot);

        return DB::connection($snapshot->connection_name);
    }

    public function connection(CompanySnapshot $snapshot): Connection
    {
        $this->configureConnection($snapshot);

        return DB::connection($snapshot->connection_name);
    }

    private function basePath(): string
    {
        return (string) config('company-snapshots.path', storage_path('app/company-snapshots'));
    }

    private function ensureFileExists(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }

    private function configureConnection(CompanySnapshot $snapshot): void
    {
        Config::set('database.connections.'.$snapshot->connection_name, [
            'driver' => 'sqlite',
            'database' => $snapshot->database_path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
            'transaction_mode' => 'IMMEDIATE',
        ]);

        DB::purge($snapshot->connection_name);
    }

    private function ensureSchema(CompanySnapshot $snapshot): void
    {
        $connection = $snapshot->connection_name;

        if (! Schema::connection($connection)->hasTable('meta')) {
            Schema::connection($connection)->create('meta', function ($table): void {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::connection($connection)->hasTable('transactions')) {
            Schema::connection($connection)->create('transactions', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('netsuite_id')->unique();
                $table->string('tranid')->nullable()->index();
                $table->string('type')->nullable()->index();
                $table->string('status')->nullable()->index();
                $table->date('trandate')->nullable()->index();
                $table->decimal('total', 15, 2)->default(0);
                $table->decimal('foreign_total', 15, 2)->default(0);
                $table->string('currency')->nullable();
                $table->text('memo')->nullable();
                $table->timestamp('last_modified_at')->nullable()->index();
                $table->text('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::connection($connection)->hasTable('transaction_lines')) {
            Schema::connection($connection)->create('transaction_lines', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('transaction_netsuite_id')->index();
                $table->string('line_id')->nullable()->index();
                $table->unsignedBigInteger('item_id')->nullable()->index();
                $table->string('item_name')->nullable();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('rate', 15, 4)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->text('memo')->nullable();
                $table->text('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['transaction_netsuite_id', 'line_id']);
            });
        }

        if (! Schema::connection($connection)->hasTable('sync_state')) {
            Schema::connection($connection)->create('sync_state', function ($table): void {
                $table->string('scope')->primary();
                $table->string('cursor_value')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->text('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection($connection)->hasTable('schema_info')) {
            Schema::connection($connection)->create('schema_info', function ($table): void {
                $table->unsignedInteger('version')->primary();
                $table->timestamp('created_at')->nullable();
            });

            DB::connection($connection)->table('schema_info')->insert([
                'version' => CompanySnapshot::SCHEMA_VERSION,
                'created_at' => now(),
            ]);
        }
    }
}
