<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'is_disabled')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('is_disabled')->default(false)->after('excel_row_id');
            $table->index('is_disabled', 'companies_is_disabled_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'is_disabled')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('companies_is_disabled_idx');
            $table->dropColumn('is_disabled');
        });
    }
};
