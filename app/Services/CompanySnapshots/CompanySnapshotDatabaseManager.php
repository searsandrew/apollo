<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
                $table->string('other_ref_num')->nullable()->index();
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

        if (Schema::connection($connection)->hasTable('transactions')
            && ! Schema::connection($connection)->hasColumn('transactions', 'other_ref_num')) {
            Schema::connection($connection)->table('transactions', function ($table): void {
                $table->string('other_ref_num')->nullable()->index();
            });
        }

        if (Schema::connection($connection)->hasTable('transactions')) {
            Schema::connection($connection)->table('transactions', function ($table) use ($connection): void {
                if (! Schema::connection($connection)->hasColumn('transactions', 'billing_address')) {
                    $table->text('billing_address')->nullable();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'shipping_address')) {
                    $table->text('shipping_address')->nullable();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'terms_id')) {
                    $table->string('terms_id')->nullable()->index();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'terms_name')) {
                    $table->string('terms_name')->nullable();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'ship_date')) {
                    $table->date('ship_date')->nullable()->index();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'ship_method_id')) {
                    $table->string('ship_method_id')->nullable()->index();
                }

                if (! Schema::connection($connection)->hasColumn('transactions', 'ship_method_name')) {
                    $table->string('ship_method_name')->nullable();
                }
            });
        }

        if (! Schema::connection($connection)->hasTable('transaction_lines')) {
            Schema::connection($connection)->create('transaction_lines', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('transaction_netsuite_id')->index();
                $table->string('line_id')->nullable()->index();
                $table->unsignedBigInteger('item_id')->nullable()->index();
                $table->string('item_name')->nullable();
                $table->string('item_number')->nullable()->index();
                $table->text('description')->nullable();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('quantity_backordered', 15, 4)->default(0);
                $table->decimal('rate', 15, 4)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->text('memo')->nullable();
                $table->boolean('is_mainline')->default(false)->index();
                $table->boolean('is_tax_line')->default(false)->index();
                $table->boolean('is_discount_line')->default(false)->index();
                $table->string('line_type')->nullable()->index();
                $table->text('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['transaction_netsuite_id', 'line_id']);
            });
        }

        if (Schema::connection($connection)->hasTable('transaction_lines')) {
            Schema::connection($connection)->table('transaction_lines', function ($table) use ($connection): void {
                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'item_number')) {
                    $table->string('item_number')->nullable()->index();
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'description')) {
                    $table->text('description')->nullable();
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'quantity_backordered')) {
                    $table->decimal('quantity_backordered', 15, 4)->default(0);
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'is_mainline')) {
                    $table->boolean('is_mainline')->default(false)->index();
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'is_tax_line')) {
                    $table->boolean('is_tax_line')->default(false)->index();
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'is_discount_line')) {
                    $table->boolean('is_discount_line')->default(false)->index();
                }

                if (! Schema::connection($connection)->hasColumn('transaction_lines', 'line_type')) {
                    $table->string('line_type')->nullable()->index();
                }
            });
        }

        if (! Schema::connection($connection)->hasTable('transaction_links')) {
            Schema::connection($connection)->create('transaction_links', function ($table): void {
                $table->id();
                $table->string('link_key')->unique();
                $table->unsignedBigInteger('previous_transaction_netsuite_id')->index();
                $table->string('previous_line_id')->nullable()->index();
                $table->string('previous_transaction_type')->nullable()->index();
                $table->string('previous_transaction_number')->nullable();
                $table->timestamp('previous_last_modified_at')->nullable()->index();
                $table->unsignedBigInteger('next_transaction_netsuite_id')->index();
                $table->string('next_line_id')->nullable()->index();
                $table->string('next_transaction_type')->nullable()->index();
                $table->string('next_transaction_number')->nullable();
                $table->timestamp('next_last_modified_at')->nullable()->index();
                $table->string('link_type')->nullable()->index();
                $table->text('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::connection($connection)->hasTable('transaction_tracking_numbers')) {
            Schema::connection($connection)->create('transaction_tracking_numbers', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('transaction_netsuite_id')->index();
                $table->string('tracking_number')->index();
                $table->text('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['transaction_netsuite_id', 'tracking_number']);
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

        $schemaInfoExists = Schema::connection($connection)->hasTable('schema_info');
        $currentVersion = $schemaInfoExists
            ? (int) (DB::connection($connection)->table('schema_info')->max('version') ?? 0)
            : 0;

        if (! $schemaInfoExists) {
            Schema::connection($connection)->create('schema_info', function ($table): void {
                $table->unsignedInteger('version')->primary();
                $table->timestamp('created_at')->nullable();
            });
        }

        if ($currentVersion < CompanySnapshot::SCHEMA_VERSION) {
            $this->normalizeTransactionDates($connection);

            DB::connection($connection)->table('schema_info')->updateOrInsert([
                'version' => CompanySnapshot::SCHEMA_VERSION,
            ], [
                'created_at' => now(),
            ]);
        }
    }

    private function normalizeTransactionDates(string $connection): void
    {
        $database = DB::connection($connection);

        $database->table('transactions')
            ->select(['id', 'trandate'])
            ->whereNotNull('trandate')
            ->where('trandate', '!=', '')
            ->whereRaw("trandate NOT GLOB '????-??-??'")
            ->orderBy('id')
            ->chunkById(500, function (Collection $transactions) use ($database): void {
                $now = now();

                foreach ($transactions as $transaction) {
                    $normalizedDate = $this->normalizeDateString($transaction->trandate);

                    if ($normalizedDate === $transaction->trandate) {
                        continue;
                    }

                    $database->table('transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'trandate' => $normalizedDate,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
