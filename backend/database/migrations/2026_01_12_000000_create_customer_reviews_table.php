<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_reviews')) {
            return;
        }

        Schema::create('customer_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->boolean('show_name')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable(); // helix_users.id
            $table->string('admin_note', 255)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('source_page', 120)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'customer_reviews_status_created_index');
            $table->index(['ip_hash', 'created_at'], 'customer_reviews_ip_hash_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reviews');
    }
};

