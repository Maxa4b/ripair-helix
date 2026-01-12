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
        if (Schema::hasTable('helix_appointment_notes') || ! Schema::hasTable('appointments')) {
            return;
        }

        Schema::create('helix_appointment_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('appointment_id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->text('body');
            $table->enum('visibility', ['internal', 'technician', 'public'])->default('internal');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at'], 'helix_notes_appt_created_idx');
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
        Schema::dropIfExists('helix_appointment_notes');
    }
};
