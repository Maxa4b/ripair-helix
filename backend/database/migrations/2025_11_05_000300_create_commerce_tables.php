<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('provider_key');
            $table->json('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('carrier')->nullable();
            $table->enum('mode', ['home', 'relay', 'atelier']);
            $table->boolean('requires_tracking')->default(true);
            $table->decimal('min_cart_amount', 10, 2)->nullable();
            $table->decimal('max_cart_amount', 10, 2)->nullable();
            $table->decimal('base_price_ht', 10, 2)->default(0);
            $table->decimal('base_price_ttc', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(20.00);
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pickup_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('postal_code');
            $table->string('city');
            $table->string('country_code', 2)->default('FR');
            $table->string('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['amount', 'percent', 'shipping']);
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->unsignedInteger('max_usage')->nullable();
            $table->unsignedInteger('max_usage_per_user')->nullable();
            $table->decimal('min_cart_amount', 10, 2)->nullable();
            $table->boolean('only_pro')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promo_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['promo_code_id', 'user_id', 'order_id']);
        });

        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->unique();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('subtotal_ht', 10, 2)->default(0);
            $table->decimal('subtotal_ttc', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('shipping_ht', 10, 2)->default(0);
            $table->decimal('shipping_ttc', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_ht', 10, 2);
            $table->decimal('unit_price_ttc', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number')->unique();
            $table->enum('status', ['awaiting_payment', 'paid', 'preparing', 'shipped', 'delivered', 'cancelled'])->default('awaiting_payment');
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->enum('fulfillment_status', ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->boolean('is_pro_order')->default(false);
            $table->boolean('requires_invoice')->default(true);
            $table->json('billing_address');
            $table->json('shipping_address');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('subtotal_ht', 10, 2);
            $table->decimal('subtotal_ttc', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('shipping_ht', 10, 2)->default(0);
            $table->decimal('shipping_ttc', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2);
            $table->decimal('grand_total', 10, 2);
            $table->decimal('grand_total_ht', 10, 2);
            $table->string('payment_method')->nullable();
            $table->string('customer_note')->nullable();
            $table->string('internal_note')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('reference_internal');
            $table->string('reference_supplier')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_ht', 10, 2);
            $table->decimal('unit_price_ttc', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(20.00);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('transaction_reference')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->enum('status', ['pending', 'authorized', 'captured', 'failed', 'refunded']);
            $table->boolean('requires_3ds')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->string('tracking_number')->nullable();
            $table->enum('status', ['pending', 'in_transit', 'delivered', 'issue']);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->boolean('is_pro')->default(false);
            $table->decimal('total_ht', 10, 2);
            $table->decimal('total_ttc', 10, 2);
            $table->decimal('tax_total', 10, 2);
            $table->string('document_path')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('promo_code_usages');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('pickup_locations');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('carrier_integrations');
    }
};
