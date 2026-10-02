<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role form fields that were inert (#25). The Admin → Roles create/edit form
 * shows "Scope" and "Description", but the controller only ever persisted the
 * name + permissions, so those inputs did nothing. Add the two columns so the
 * form actually stores them. (The "Start from template" dropdown is removed from
 * the form instead of half-wired.) Additive + guarded — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (! Schema::hasColumn('roles', 'scope')) {
                    $table->string('scope', 20)->default('workspace')->after('guard_name');
                }
                if (! Schema::hasColumn('roles', 'description')) {
                    $table->string('description', 500)->nullable()->after('scope');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                foreach (['scope', 'description'] as $col) {
                    if (Schema::hasColumn('roles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
