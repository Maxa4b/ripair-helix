<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_reviews')) {
            return;
        }

        if (Schema::hasColumn('customer_reviews', 'image_path')) {
            return;
        }

        Schema::table('customer_reviews', function (Blueprint $table): void {
            $table->string('image_path', 255)->nullable()->after('admin_note');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('customer_reviews')) {
            return;
        }

        if (!Schema::hasColumn('customer_reviews', 'image_path')) {
            return;
        }

        Schema::table('customer_reviews', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};

