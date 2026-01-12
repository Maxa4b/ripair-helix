<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livreo_supplier_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id'); // id de la commande côté e-commerce (DB externe)
            $table->string('supplier', 60)->default('mobilesentrix');
            $table->string('supplier_order_number')->nullable();
            $table->enum('status', ['to_order', 'ordered', 'received', 'problem'])->default('to_order');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('invoice_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'supplier']);
        });

        Schema::create('livreo_supplier_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained('livreo_supplier_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('order_item_id')->nullable(); // e-commerce order_items.id
            $table->unsignedBigInteger('product_variant_id')->nullable(); // e-commerce product_variants.id
            $table->string('supplier_sku')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->timestamps();
            $table->index(['order_item_id']);
        });

        Schema::create('livreo_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable(); // helix_users.id
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            $table->string('action', 80);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreo_audit_events');
        Schema::dropIfExists('livreo_supplier_order_items');
        Schema::dropIfExists('livreo_supplier_orders');
    }
};

