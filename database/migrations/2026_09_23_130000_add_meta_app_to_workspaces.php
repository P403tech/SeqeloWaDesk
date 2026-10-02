<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace Meta app (manual keys) for Facebook + Instagram connect — so a
 * client can bring their OWN Meta app (App ID + App Secret) instead of the
 * platform admin's, exactly like the WhatsApp "Using your own Meta app?" option.
 * Facebook and Instagram share one Meta app, so one credential set covers both.
 * Gated by the admin toggle `meta_allow_manual_app`. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'meta_app_id')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->string('meta_app_id')->nullable()->after('captcha_secret');
                $table->text('meta_app_secret')->nullable()->after('meta_app_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['meta_app_id', 'meta_app_secret'] as $c) {
            if (Schema::hasColumn('workspaces', $c)) {
                Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
