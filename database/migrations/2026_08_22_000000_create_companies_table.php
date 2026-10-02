<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company / Organization CRM entity (Phase 1). Groups contacts + deals under one
 * business so the workspace has a B2B layer: "ACME has 3 contacts, 2 open deals,
 * ₹50k invoiced". Contacts and deals gain a nullable company_id FK. Encrypted
 * fields follow the same at-rest pattern as contacts (name/email/phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step is guarded independently. MySQL has NO transactional DDL,
        // so if this migration dies partway (it did on a live install: the
        // companies table was created, then the contacts ALTER failed) the
        // completed steps stay and the migration is never recorded as run.
        // Re-running then aborts on "table companies already exists" and the
        // install is stuck for good — /companies 500s with
        // "Unknown column 'contacts.company_id'" because the second half never
        // applied. Guarding every step makes the migration resumable.
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();       // creator
                $table->unsignedBigInteger('owner_user_id')->nullable(); // assigned owner
                $table->text('name');                                    // encrypted
                $table->text('email')->nullable();                       // encrypted
                $table->text('phone')->nullable();                       // encrypted
                $table->string('website')->nullable();
                $table->string('industry')->nullable();
                $table->string('size_range')->nullable();                // e.g. 1-10, 11-50
                $table->text('address')->nullable();
                $table->text('notes')->nullable();
                $table->json('custom_attributes')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'created_at']);
            });
        }

        // withCount('contacts') on the Company model reads this column on every
        // /companies render, so a missing one is a hard 500, not a degraded page.
        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'company_id')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('workspace_id')->index();
            });
        }

        if (Schema::hasTable('deals') && ! Schema::hasColumn('deals', 'company_id')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('contact_id')->index();
            });
        }
    }

    public function down(): void
    {
        // Guarded the same way — a rollback after a half-applied up() must not
        // die trying to drop a column that was never added.
        if (Schema::hasTable('deals') && Schema::hasColumn('deals', 'company_id')) {
            Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'company_id')) {
            Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
        Schema::dropIfExists('companies');
    }
};
