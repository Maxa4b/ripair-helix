<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('livreo_supplier_orders')) {
            Schema::table('livreo_supplier_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_carrier')) {
                    $table->string('supplier_carrier', 80)->nullable()->after('supplier_order_number');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_tracking_number')) {
                    $table->string('supplier_tracking_number', 80)->nullable()->after('supplier_carrier');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_tracking_url')) {
                    $table->string('supplier_tracking_url')->nullable()->after('supplier_tracking_number');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_shipped_at')) {
                    $table->timestamp('supplier_shipped_at')->nullable()->after('supplier_tracking_url');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_email_message_id')) {
                    $table->string('supplier_email_message_id', 255)->nullable()->after('supplier_shipped_at');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_email_subject')) {
                    $table->string('supplier_email_subject', 255)->nullable()->after('supplier_email_message_id');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_email_from')) {
                    $table->string('supplier_email_from', 190)->nullable()->after('supplier_email_subject');
                }
                if (! Schema::hasColumn('livreo_supplier_orders', 'supplier_email_received_at')) {
                    $table->timestamp('supplier_email_received_at')->nullable()->after('supplier_email_from');
                }
            });
        }

        if (! Schema::hasTable('livreo_supplier_mail_events')) {
            Schema::create('livreo_supplier_mail_events', function (Blueprint $table): void {
                $table->id();
                $table->string('supplier', 60)->default('mobilesentrix');
                $table->string('message_id', 255)->unique();
                $table->timestamp('received_at')->nullable();
                $table->string('from_email', 190)->nullable();
                $table->string('subject', 255)->nullable();
                $table->string('supplier_order_number', 190)->nullable();
                $table->string('carrier', 80)->nullable();
                $table->string('tracking_number', 80)->nullable();
                $table->unsignedBigInteger('matched_supplier_order_id')->nullable();
                $table->unsignedBigInteger('matched_order_id')->nullable();
                $table->text('preview')->nullable();
                $table->timestamps();

                $table->index(['supplier', 'supplier_order_number']);
                $table->index(['matched_supplier_order_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('livreo_supplier_mail_events')) {
            Schema::dropIfExists('livreo_supplier_mail_events');
        }
    }
};

