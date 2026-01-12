<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->after('email');
            $table->string('preferred_locale', 8)->default('fr')->after('remember_token');
            $table->string('primary_role', 32)->default('customer')->after('preferred_locale');
            $table->enum('account_type', ['standard', 'pro', 'staff'])->default('standard')->after('primary_role');
            $table->enum('pro_status', ['pending', 'approved', 'rejected'])->default('pending')->after('account_type');
            $table->string('company_name')->nullable()->after('pro_status');
            $table->string('siret', 20)->nullable()->after('company_name');
            $table->string('vat_number', 32)->nullable()->after('siret');
            $table->timestamp('pro_validated_at')->nullable()->after('vat_number');
            $table->boolean('accepts_marketing')->default(false)->after('pro_validated_at');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'preferred_locale',
                'primary_role',
                'account_type',
                'pro_status',
                'company_name',
                'siret',
                'vat_number',
                'pro_validated_at',
                'accepts_marketing',
            ]);
        });
    }
};
