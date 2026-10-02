<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Publishes a customer-facing blog post explaining Meta's WhatsApp Business
 * Platform pricing change effective 1 October 2026 (service messages become
 * paid; 1,000 free per number/month; in-window utility templates now charged).
 * Idempotent — re-running updates the same post by slug, never duplicates.
 *   php artisan db:seed --class=Database\\Seeders\\MetaPricingChange2026Seeder
 */
class MetaPricingChange2026Seeder extends Seeder
{
    public function run(): void
    {
        $catId = BlogCategory::updateOrCreate(
            ['slug' => Str::slug('WhatsApp API')],
            ['name' => 'WhatsApp API', 'description' => 'Cloud API, numbers, verification and setup.']
        )->id;

        $title = 'Meta WhatsApp pricing changes from 1 October 2026: what customers need to know';
        $excerpt = 'From 1 October 2026 Meta charges for service messages on the WhatsApp Business Platform. Every number gets 1,000 free service messages a month; in-window utility templates are now billable too. Here is what changes and how to keep costs low.';

        $body =
            '<p>Meta is updating how the <strong>WhatsApp Business Platform (Cloud API)</strong> is billed. '
            . 'This is a <strong>Meta change, not a WaDesk change</strong> — it applies to every business on the official API, whichever tool they use. '
            . 'The change takes effect on <strong>1 October 2026</strong>. The free WhatsApp and WhatsApp Business phone apps are not affected.</p>'

            . '<h2>What is changing</h2>'
            . '<ul>'
            . '<li><strong>Service messages become paid.</strong> Free-form replies you send inside the 24-hour customer-service window — previously free — are now charged per message.</li>'
            . '<li><strong>1,000 free service messages every month, per phone number.</strong> You are only billed from the 1,001st service message. The allowance resets each month and does <strong>not</strong> roll over.</li>'
            . '<li><strong>In-window utility templates are now charged.</strong> Utility templates sent in reply to a customer inside the 24-hour window had been free since July 2025; from 1 October 2026 they are billed.</li>'
            . '<li><strong>Same rate as utility &amp; authentication.</strong> Service messages are priced at the same per-message rate as utility and authentication templates for each country. Meta publishes the exact country rates in advance.</li>'
            . '</ul>'

            . '<h2>What is NOT changing</h2>'
            . '<ul>'
            . '<li><strong>Marketing templates</strong> are unaffected by this update.</li>'
            . '<li>The <strong>free WhatsApp / WhatsApp Business apps</strong> are not affected — only the Business Platform (API).</li>'
            . '<li>Billing is handled by <strong>Meta on your WhatsApp account</strong>, not by WaDesk. WaDesk does not add any charge on top.</li>'
            . '</ul>'

            . '<h2>What it means for you</h2>'
            . '<p>For most businesses the impact is small: your first <strong>1,000 support replies per number each month stay free</strong>. '
            . 'If you handle very high support volumes, you may see a modest new cost once you pass the free allowance.</p>'

            . '<h2>How to keep costs low</h2>'
            . '<ul>'
            . '<li>Resolve customer conversations <strong>within the free 1,000/month service-message allowance</strong> where possible.</li>'
            . '<li>Let customers <strong>start the conversation</strong> (service window) instead of always re-opening with a template.</li>'
            . '<li>Bundle related updates into a single 24-hour window rather than spreading them out.</li>'
            . '<li>Use <strong>utility templates outside the window only when genuinely needed</strong>.</li>'
            . '</ul>'

            . '<p>Nothing in WaDesk changes because of this — your inbox, campaigns, flows and templates all keep working exactly as before. '
            . 'The only difference is on your Meta bill. If you have questions about your specific plan or country rates, check Meta\'s current rate card for your audience.</p>';

        BlogPost::updateOrCreate(
            ['slug' => Str::slug($title)],
            [
                'title'            => $title,
                'excerpt'          => $excerpt,
                'body'             => $body,
                'category_id'      => $catId,
                'tags'             => ['pricing', 'Meta', 'service messages', 'WhatsApp Business API', 'October 2026'],
                'author_name'      => 'WaDesk Team',
                'status'           => 'published',
                'published_at'     => now(),
                'is_featured'      => true,
                'meta_title'       => 'Meta WhatsApp pricing changes 1 October 2026 | WaDesk',
                'meta_description' => $excerpt,
                'meta_keywords'    => 'WhatsApp pricing 2026, Meta service message pricing, WhatsApp Business API cost, October 2026',
            ]
        );
    }
}
