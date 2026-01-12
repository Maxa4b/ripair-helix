<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('appointments')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'assigned_user_id')) {
                $table->unsignedBigInteger('assigned_user_id')->nullable()->after('status');
            }
            if (! Schema::hasColumn('appointments', 'store_code')) {
                $table->string('store_code', 20)->default('CESTAS')->after('assigned_user_id');
            }
            if (! Schema::hasColumn('appointments', 'price_estimate_cents')) {
                $table->integer('price_estimate_cents')->nullable()->after('store_code');
            }
            if (! Schema::hasColumn('appointments', 'discount_pct')) {
                $table->decimal('discount_pct', 5, 2)->nullable()->after('price_estimate_cents');
            }
            if (! Schema::hasColumn('appointments', 'source')) {
                $table->enum('source', ['web', 'manual', 'import', 'helix'])->default('web')->after('discount_pct');
            }
            if (! Schema::hasColumn('appointments', 'status_updated_at')) {
                $table->dateTime('status_updated_at')->nullable()->after('end_datetime');
            }
            if (! Schema::hasColumn('appointments', 'internal_notes')) {
                $table->text('internal_notes')->nullable()->after('status_updated_at');
            }
            if (! Schema::hasColumn('appointments', 'customer_address')) {
                $table->string('customer_address', 255)->nullable()->after('internal_notes');
            }
            if (! Schema::hasColumn('appointments', 'meta')) {
                $table->json('meta')->nullable()->after('customer_address');
            }
        });

        if (Schema::hasColumn('appointments', 'assigned_user_id')
            && Schema::hasTable('helix_users')
            && ! $this->foreignKeyExists('appointments', 'appointments_assigned_user_id_foreign')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->foreign('assigned_user_id')
                    ->references('id')
                    ->on('helix_users')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('appointments', 'idx_appointments_schedule')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index(['start_datetime', 'end_datetime'], 'idx_appointments_schedule');
            });
        }
        if (! $this->indexExists('appointments', 'idx_appointments_status')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index('status', 'idx_appointments_status');
            });
        }
        if (! $this->indexExists('appointments', 'idx_appointments_assignee_start')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index(['assigned_user_id', 'start_datetime'], 'idx_appointments_assignee_start');
            });
        }
        if (! $this->indexExists('appointments', 'idx_appointments_source')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index('source', 'idx_appointments_source');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid data loss on production environments.
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$indexName]);

        return ! empty($result);
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $database = DB::getDatabaseName();
        $result = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$database, $table, $foreignKey]
        );

        return ! empty($result);
    }
};
