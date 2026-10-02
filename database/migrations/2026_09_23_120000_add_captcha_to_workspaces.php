<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace reCAPTCHA keys — for white-label custom domains. The platform's
 * global site key is registered only for the platform host, so on a connected
 * workspace subdomain Google returns "Invalid domain for site key". A workspace
 * can now supply its OWN keys (valid for its domain); when it hasn't, captcha is
 * simply skipped on that domain instead of erroring. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'captcha_enabled')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->boolean('captcha_enabled')->default(false)->after('cname_verified');
                $table->string('captcha_version')->default('v2')->after('captcha_enabled');
                $table->string('captcha_site_key')->nullable()->after('captcha_version');
                $table->text('captcha_secret')->nullable()->after('captcha_site_key');
            });
        }
    }

    public function down(): void
    {
        foreach (['captcha_enabled', 'captcha_version', 'captcha_site_key', 'captcha_secret'] as $c) {
            if (Schema::hasColumn('workspaces', $c)) {
                Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
