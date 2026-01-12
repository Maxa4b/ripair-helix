<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rma_number')->unique();
            $table->enum('status', ['requested', 'received', 'in_review', 'accepted', 'refused', 'refunded', 'replacement_sent']);
            $table->enum('reason', ['defect', 'incompatible', 'other']);
            $table->text('description')->nullable();
            $table->string('evidence_path')->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('rma_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rma_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('resolution', ['refund', 'replacement', 'repair', 'credit'])->nullable();
            $table->text('condition_report')->nullable();
            $table->timestamps();
        });

        Schema::create('rma_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rma_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rma_events');
        Schema::dropIfExists('rma_items');
        Schema::dropIfExists('rmas');
    }
};
