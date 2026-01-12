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
        if (Schema::hasTable('helix_appointment_events') || ! Schema::hasTable('appointments')) {
            return;
        }

        Schema::create('helix_appointment_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('appointment_id');
            $table->enum('type', ['status_change', 'notification', 'inventory', 'payment', 'custom']);
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['type', 'created_at'], 'helix_events_type_created_idx');
            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->onDelete('cascade');

            if (Schema::hasTable('helix_users')) {
                $table->foreign('author_id')
                    ->references('id')
                    ->on('helix_users')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helix_appointment_events');
    }
};
