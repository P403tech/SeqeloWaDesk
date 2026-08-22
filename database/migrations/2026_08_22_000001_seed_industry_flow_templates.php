<?php

use Database\Seeders\FlowTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Existing installs still have the original 5 gallery rows. The industry
 * pack lives in FlowTemplateSeeder; migrate (Railway boot) must upsert it
 * or /flows keeps showing only those five until someone seeds by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_templates')) {
            return;
        }

        (new FlowTemplateSeeder)->run();
    }

    public function down(): void
    {
        // Do not delete: tenants and admins may have cloned or edited rows.
    }
};
