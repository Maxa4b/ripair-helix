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
        if (Schema::hasTable('helix_availability_blocks')) {
            return;
        }

        Schema::create('helix_availability_blocks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('created_by');
            $table->enum('type', ['open', 'closed', 'maintenance', 'offsite'])->default('open');
            $table->string('title', 140)->nullable();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('recurrence_rule', 255)->nullable();
            $table->dateTime('recurrence_until')->nullable();
            $table->char('color', 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['start_datetime', 'end_datetime'], 'helix_availability_schedule_idx');
            $table->index('type', 'helix_availability_type_idx');
            $table->foreign('created_by')
                ->references('id')
                ->on('helix_users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helix_availability_blocks');
    }
};
