<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('customer_code')->unique();
            $table->enum('type', ['standard', 'pro']);
            $table->enum('pro_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('siret', 20)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('default_currency', 3)->default('EUR');
            $table->decimal('credit_limit', 10, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->boolean('allow_deferred_payment')->default(false);
            $table->boolean('auto_apply_pro_discounts')->default(true);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();
            $table->string('company_name')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('postal_code', 15);
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('country_code', 2)->default('FR');
            $table->string('instructions')->nullable();
            $table->enum('type', ['billing', 'shipping', 'both'])->default('both');
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->timestamps();
        });

        Schema::create('tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 5, 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('pro_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity')->default(1);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->boolean('stackable')->default(false);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pro_pricing_rules');
        Schema::dropIfExists('tax_profiles');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('customer_profiles');
    }
};
