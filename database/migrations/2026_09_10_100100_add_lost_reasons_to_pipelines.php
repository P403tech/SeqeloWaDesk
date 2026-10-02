<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace-authored "lost reason" list, stored per pipeline.
 *
 * `deals.lost_reason` was already captured, but only as free text typed into
 * the Mark-Lost box — so two agents wrote "too expensive" and "Price" for the
 * same thing and the reports could never group them. This column holds the
 * pipeline's own picklist; the Mark-Lost modal renders a select when it is
 * non-empty and keeps the free-text box when it is empty, so every existing
 * pipeline behaves exactly as before until someone configures a list.
 *
 * Shape: JSON array of strings, e.g. ["Price too high","Went with competitor"].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipelines')) {
            return;
        }
        // Guard the column too — the updater re-runs migrations on installs
        // that may already carry it from a partial upgrade.
        if (Schema::hasColumn('pipelines', 'lost_reasons')) {
            return;
        }

        Schema::table('pipelines', function (Blueprint $t) {
            $t->json('lost_reasons')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pipelines') && Schema::hasColumn('pipelines', 'lost_reasons')) {
            Schema::table('pipelines', function (Blueprint $t) {
                $t->dropColumn('lost_reasons');
            });
        }
    }
};
