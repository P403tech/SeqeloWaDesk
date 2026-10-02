<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace INSTAGRAM-LOGIN app credentials (Instagram API with Instagram
 * Login) — SEPARATE from the Facebook Meta app (meta_app_id/secret).
 *
 * Instagram's "API setup with Instagram login" has its OWN Instagram app ID +
 * secret (shown on the Instagram-API-Setup page, e.g. 4329377027362895), which
 * differs from the Facebook App ID. Connecting Instagram through THIS app uses
 * `instagram_business_manage_messages`, which Meta grants on connection — so
 * DMs are delivered WITHOUT the Advanced-Access review the Facebook-Login
 * `instagram_manage_messages` path requires. This lets a workspace receive
 * Instagram DMs on its own app while Facebook stays on the manual page-token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'ig_login_app_id')) {
                $table->string('ig_login_app_id')->nullable()->after('meta_app_secret');
            }
            if (! Schema::hasColumn('workspaces', 'ig_login_app_secret')) {
                $table->text('ig_login_app_secret')->nullable()->after('ig_login_app_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach (['ig_login_app_id', 'ig_login_app_secret'] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
