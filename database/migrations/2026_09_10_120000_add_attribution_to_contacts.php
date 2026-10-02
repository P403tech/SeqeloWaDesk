<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Click-to-WhatsApp attribution on the contact.
 *
 * Meta sends a `referral` block on the FIRST message after someone taps a
 * Click-to-WhatsApp ad — which ad, which campaign creative, and the `ctwa_clid`
 * that ties the conversation back to Ads Manager. The webhook was dropping it
 * entirely, so a workspace running CTWA ads could see the conversations arrive
 * but never tell which ad paid for them.
 *
 * Stored on the CONTACT (not only the thread) because the question people
 * actually ask is "where did this customer come from?", and that has to survive
 * the thread being archived, merged, or re-opened months later.
 *
 * first_* is preserved on later clicks so the original source is never lost;
 * the newest click is kept alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'attribution')) {
            Schema::table('contacts', function (Blueprint $t) {
                $t->json('attribution')->nullable()->after('custom_attributes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'attribution')) {
            Schema::table('contacts', function (Blueprint $t) {
                $t->dropColumn('attribution');
            });
        }
    }
};
