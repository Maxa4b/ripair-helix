<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helix_users', function (Blueprint $table): void {
            if (!Schema::hasColumn('helix_users', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helix_users', function (Blueprint $table): void {
            if (Schema::hasColumn('helix_users', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
