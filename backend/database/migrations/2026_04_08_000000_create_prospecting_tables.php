<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('company_id', 26)->unique();
                $table->string('name', 255);
                $table->string('siren', 12)->nullable();
                $table->string('siret', 18)->nullable();
                $table->string('segment', 120)->nullable();
                $table->string('source', 120)->nullable();
                $table->string('website', 255)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('country', 120)->nullable()->default('France');
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->string('google_place_id', 190)->nullable();
                $table->unsignedSmallInteger('relevance_score')->default(0);
                $table->string('contact_status', 32)->default('non_contacte');
                $table->string('contact_owner', 190)->nullable();
                $table->timestamp('first_contact_at')->nullable();
                $table->timestamp('last_contact_at')->nullable();
                $table->text('notes')->nullable();
                $table->string('excel_row_id', 64)->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();

                $table->index('siren', 'companies_siren_idx');
                $table->index('siret', 'companies_siret_idx');
                $table->index('segment', 'companies_segment_idx');
                $table->index('source', 'companies_source_idx');
                $table->index('email', 'companies_email_idx');
                $table->index('postal_code', 'companies_postal_code_idx');
                $table->index('city', 'companies_city_idx');
                $table->index('google_place_id', 'companies_google_place_id_idx');
                $table->index('contact_status', 'companies_contact_status_idx');
                $table->index('contact_owner', 'companies_contact_owner_idx');
                $table->index(['lat', 'lng'], 'companies_lat_lng_idx');
                $table->index(['contact_status', 'segment'], 'companies_status_segment_idx');
                $table->index('excel_row_id', 'companies_excel_row_id_idx');
            });
        }

        if (! Schema::hasTable('company_contact_history')) {
            Schema::create('company_contact_history', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('previous_status', 32)->nullable();
                $table->string('new_status', 32)->nullable();
                $table->string('previous_owner', 190)->nullable();
                $table->string('new_owner', 190)->nullable();
                $table->text('previous_notes')->nullable();
                $table->text('new_notes')->nullable();
                $table->string('source', 32)->default('ui');
                $table->text('change_note')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('helix_users')->nullOnDelete();
                $table->string('changed_by_name', 190)->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();

                $table->index(['company_id', 'changed_at'], 'company_contact_history_company_changed_idx');
                $table->index('source', 'company_contact_history_source_idx');
            });
        }

        if (! Schema::hasTable('excel_sync_jobs')) {
            Schema::create('excel_sync_jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('mode', 16);
                $table->string('status', 16)->default('pending');
                $table->string('file_path', 255)->nullable();
                $table->string('sheet_name', 120)->nullable();
                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_processed')->default(0);
                $table->unsignedInteger('rows_updated')->default(0);
                $table->unsignedInteger('rows_skipped')->default(0);
                $table->unsignedInteger('rows_failed')->default(0);
                $table->json('payload')->nullable();
                $table->json('error_payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('helix_users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['mode', 'status'], 'excel_sync_jobs_mode_status_idx');
            });
        }

        if (! Schema::hasTable('import_jobs')) {
            Schema::create('import_jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('source', 120)->nullable();
                $table->string('status', 16)->default('pending');
                $table->string('file_path', 255)->nullable();
                $table->string('segment', 120)->nullable();
                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_processed')->default(0);
                $table->unsignedInteger('rows_created')->default(0);
                $table->unsignedInteger('rows_updated')->default(0);
                $table->unsignedInteger('rows_deduplicated')->default(0);
                $table->unsignedInteger('rows_rejected')->default(0);
                $table->json('payload')->nullable();
                $table->json('error_payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('helix_users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['source', 'status'], 'import_jobs_source_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('excel_sync_jobs');
        Schema::dropIfExists('company_contact_history');
        Schema::dropIfExists('companies');
    }
};
