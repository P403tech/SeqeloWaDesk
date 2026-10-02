<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace OWN Google + Shopify app credentials (self-serve BYO app),
 * mirroring the existing per-workspace Meta app (workspaces.meta_app_id/secret).
 *
 * A workspace that fills its own keys connects Google / Shopify through ITS
 * OWN app — no admin config or approval, and inbound webhooks verify with the
 * workspace's own secret. Left blank → falls back to the platform/admin app.
 *
 * Secrets are stored via the model's `encrypted` cast (same as meta_app_secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'google_client_id')) {
                $table->string('google_client_id')->nullable()->after('meta_app_secret');
            }
            if (! Schema::hasColumn('workspaces', 'google_client_secret')) {
                $table->text('google_client_secret')->nullable()->after('google_client_id');
            }
            if (! Schema::hasColumn('workspaces', 'shopify_client_id')) {
                $table->string('shopify_client_id')->nullable()->after('google_client_secret');
            }
            if (! Schema::hasColumn('workspaces', 'shopify_client_secret')) {
                $table->text('shopify_client_secret')->nullable()->after('shopify_client_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach (['google_client_id', 'google_client_secret', 'shopify_client_id', 'shopify_client_secret'] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
