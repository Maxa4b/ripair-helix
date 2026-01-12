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
        if (Schema::hasTable('helix_users')) {
            return;
        }

        Schema::create('helix_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 190)->unique();
            $table->string('password_hash', 255);
            $table->enum('role', ['owner', 'manager', 'technician', 'frontdesk'])->default('manager');
            $table->string('phone', 25)->nullable();
            $table->char('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['role', 'is_active'], 'helix_users_role_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helix_users');
    }
};
