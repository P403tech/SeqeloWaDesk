<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin "Push to customers" stamps which workspace template came from
 * which sample, so a later save can update unsubmitted copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_template_samples', function (Blueprint $table) {
            $table->timestamp('last_pushed_at')->nullable()->after('created_by');
        });

        Schema::table('wa_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('source_sample_id')->nullable()->index()->after('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn('source_sample_id');
        });

        Schema::table('wa_template_samples', function (Blueprint $table) {
            $table->dropColumn('last_pushed_at');
        });
    }
};
