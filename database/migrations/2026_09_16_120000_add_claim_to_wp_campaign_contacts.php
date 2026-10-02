<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stops a campaign sending the same recipient twice.
 *
 * The sender was read-then-act: SELECT the recipient, check it isn't already
 * 'sent', send, then mark it. Nothing held the row in between. Two workers on
 * the same campaign therefore both passed the check and both sent.
 *
 * Two workers is not hypothetical: CampaignScheduleSweeper treats a campaign
 * still 'running' after 45s as a dead worker and re-fires it, while the send
 * itself runs in an afterResponse() job OUTSIDE the sweep lock. A chunk that
 * is merely slow — media templates, big audiences — gets a second worker and
 * the two overlap.
 *
 * `claimed_at` makes the claim atomic: a conditional UPDATE that matches only
 * an unclaimed row, so exactly one worker can win a recipient. It also lets a
 * claim be RECOVERED — a worker killed mid-send leaves the row claimed, and
 * without a timestamp it would be stranded forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wp_campaign_contacts')) {
            return;
        }

        Schema::table('wp_campaign_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('wp_campaign_contacts', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('status');
            }
        });

        // The claim's hot path: "this campaign's rows for this contact".
        // Without it the conditional UPDATE scans the campaign's whole audience
        // on every single send.
        Schema::table('wp_campaign_contacts', function (Blueprint $table) {
            try {
                $table->index(['campaign_id', 'contact_id'], 'wpcc_campaign_contact_idx');
            } catch (\Throwable $e) {
                // already present — nothing to do
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wp_campaign_contacts')) {
            return;
        }

        Schema::table('wp_campaign_contacts', function (Blueprint $table) {
            try { $table->dropIndex('wpcc_campaign_contact_idx'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('wp_campaign_contacts', 'claimed_at')) {
                $table->dropColumn('claimed_at');
            }
        });
    }
};
