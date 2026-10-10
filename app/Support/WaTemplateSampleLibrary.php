<?php

namespace App\Support;

use App\Models\WaTemplateSample;
use Illuminate\Support\Facades\Schema;

/**
 * Ready-made WhatsApp message blueprints (Wati-style "Template Library").
 *
 * These are NOT Meta-approved templates. "Use sample" copies copy + buttons
 * into /templates/create so the operator can brand it and submit to Meta.
 *
 * Live catalog lives in wa_template_samples (admin CRUD). builtins() is the
 * shipped starter set used to seed that table, and the fallback when the
 * table is missing (unit tests / pre-migrate).
 *
 * Bodies use named tokens ({{name}}) — the create form already normalizes
 * those to positional {{1}} on save. Never start or end a body with a token
 * (Meta rejects that).
 */
class WaTemplateSampleLibrary
{
    public const CATEGORIES = [
        'festival'   => 'Festival',
        'ecommerce'  => 'Ecommerce',
        'education'  => 'Education',
        'healthcare' => 'Healthcare',
        'utility'    => 'Others',
    ];

    /**
     * Samples tenants see. DB when the table exists; otherwise the shipped set.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $fromDb = self::fromDatabase();
        if ($fromDb !== null) {
            return $fromDb;
        }

        return self::builtins();
    }

    /**
     * @return list<array<string, mixed>>|null  null = table missing / no container
     */
    private static function fromDatabase(): ?array
    {
        try {
            if (! Schema::hasTable('wa_template_samples')) {
                return null;
            }

            return WaTemplateSample::query()
                ->active()
                ->ordered()
                ->get()
                ->map(fn (WaTemplateSample $row) => $row->toLibraryArray())
                ->all();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        foreach (self::all() as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public static function categoryCounts(?string $query = null): array
    {
        $rows = self::filter($query, 'all');
        $counts = ['all' => count($rows)];
        foreach (array_keys(self::CATEGORIES) as $key) {
            $counts[$key] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row['category']] = ($counts[$row['category']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filter(?string $query = null, string $category = 'all'): array
    {
        $q = mb_strtolower(trim((string) $query));
        $out = [];
        foreach (self::all() as $row) {
            if ($category !== 'all' && $row['category'] !== $category) {
                continue;
            }
            if ($q !== '') {
                $hay = mb_strtolower($row['slug'].' '.$row['title'].' '.$row['body']);
                if (! str_contains($hay, $q)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Shipped starter catalog. Admin can hide / edit / add on top after seed.
     *
     * @return list<array<string, mixed>>
     */
    public static function builtins(): array
    {
        return [
            self::row('christmas_greetings', 'festival', 'marketing',
                'Hello {{name}}, wishing you a joyful Christmas. May this festive season bring you and your loved ones happiness and countless blessings.',
                'Happy Christmas',
                [['type' => 'quick_reply', 'text' => 'Thank you']],
                '#9B1C1C', '#166534', '🎄'),
            self::row('republic_day_discount', 'festival', 'marketing',
                'Hello {{name}}, celebrate Republic Day with us. Use code {{code}} for 20 percent off — valid this weekend only.',
                'Happy Republic Day',
                [['type' => 'visit_website', 'text' => 'Shop the offer', 'value' => 'https://example.com/republic-day']],
                '#FF9933', '#138808', '🇮🇳'),
            self::row('makar_sankranti', 'festival', 'marketing',
                'Hello {{name}}, wishing you a prosperous Makar Sankranti. May the sun radiate peace, happiness, and success in your life.',
                'Happy Makar Sankranti',
                [['type' => 'quick_reply', 'text' => 'Thank you']],
                '#F59E0B', '#DC2626', '🪁'),
            self::row('end_of_season_sale', 'ecommerce', 'marketing',
                'Hello {{name}}, our end of season sale is on. Enjoy up to 50 percent off your favourite styles until {{expiry}}.',
                'End of season sale',
                [['type' => 'visit_website', 'text' => 'Shop now', 'value' => 'https://example.com/sale']],
                '#F59E0B', '#7C2D12', '🏷️'),
            self::row('last_chance_1212', 'festival', 'marketing',
                'Hello {{name}}, last chance: our 12.12 sale ends tonight. Grab these deals before they vanish.',
                '12.12 last chance',
                [['type' => 'visit_website', 'text' => 'Shop 12.12', 'value' => 'https://example.com/12-12']],
                '#7C3AED', '#DB2777', '⏰'),
            self::row('new_year', 'festival', 'marketing',
                'Hey {{name}}, as we bid farewell to the old year and welcome the new one, we want to thank you for your support and trust.',
                'Happy New Year',
                [['type' => 'quick_reply', 'text' => 'Happy New Year']],
                '#0F172A', '#1D4ED8', '🎆'),
            self::row('valentines_day', 'festival', 'marketing',
                'Hello {{name}}, celebrate love with an exclusive Valentine offer just for you. Valid until {{expiry}}.',
                'Valentine\'s Day',
                [['type' => 'visit_website', 'text' => 'See the offer', 'value' => 'https://example.com/valentine']],
                '#BE123C', '#FB7185', '💝'),
            self::row('diwali_drop', 'festival', 'marketing',
                'Hello {{name}}, our Diwali drop is live. Use code {{code}} for {{discount}} off — ends {{expiry}}.',
                'Diwali drop',
                [['type' => 'visit_website', 'text' => 'Shop Diwali', 'value' => 'https://example.com/diwali']],
                '#B45309', '#FBBF24', '🪔'),
            self::row('management_course', 'education', 'marketing',
                'Hello {{name}}, enrol in our management course and unlock your leadership potential. Gain practical skills starting {{start_date}}.',
                'Product management masterclass',
                [['type' => 'visit_website', 'text' => 'Enrol now', 'value' => 'https://example.com/enrol']],
                '#1E3A8A', '#0284C7', '🎓'),
            self::row('class_reminder', 'education', 'utility',
                'Hi {{name}}, your class for {{course}} is scheduled tomorrow {{date}} at {{time}}. Please arrive 10 minutes early.',
                'Class reminder',
                [['type' => 'quick_reply', 'text' => 'I will attend']],
                '#0F766E', '#115E59', '📚'),
            self::row('course_enrollment', 'education', 'utility',
                'Hi {{name}}, congratulations on enrolling in {{course}}. Start: {{start_date}}. Duration: {{weeks}} weeks. Instructor: {{instructor}}.',
                'Enrolment confirmed',
                [['type' => 'quick_reply', 'text' => 'Thanks']],
                '#1D4ED8', '#312E81', '✅'),
            self::row('order_shipped', 'ecommerce', 'utility',
                'Hi {{name}}, your order {{order_id}} has shipped. Track it with {{tracking_url}} whenever you like.',
                'Order shipped',
                [['type' => 'visit_website', 'text' => 'Track order', 'value' => 'https://example.com/track']],
                '#0369A1', '#0F766E', '📦'),
            self::row('order_delivered', 'ecommerce', 'utility',
                'Hi {{name}}, your order {{order_id}} was delivered. We hope you love it. Reply if you need any help.',
                'Order delivered',
                [['type' => 'quick_reply', 'text' => 'Need help']],
                '#166534', '#4ADE80', '📬'),
            self::row('appointment_reminder', 'healthcare', 'utility',
                'Hi {{name}}, reminder of your appointment with {{doctor}} at {{clinic}} on {{date}} at {{time}}. Please arrive 10 minutes early.',
                'Appointment reminder',
                [['type' => 'quick_reply', 'text' => 'Confirm']],
                '#0F766E', '#99F6E4', '🩺'),
            self::row('prescription_renewal', 'healthcare', 'utility',
                'Hi {{name}}, time to renew your prescription for {{medication}}. Submit a renewal request before {{date}} so you do not run out.',
                'Prescription renewal',
                [['type' => 'quick_reply', 'text' => 'Renew now']],
                '#1E40AF', '#67E8F9', '💊'),
            self::row('welcome_onboard', 'utility', 'utility',
                'Hi {{name}}, welcome aboard. We are glad you joined {{company}}. Reply to this chat if you have any questions.',
                'Welcome aboard',
                [['type' => 'quick_reply', 'text' => 'Say hello']],
                '#1B4B3D', '#037D66', '👋'),

            self::row('eid_mubarak', 'festival', 'marketing',
                'Hello {{name}}, Eid Mubarak. May this Eid bring peace, joy, and prosperity to you and your family.',
                'Eid Mubarak',
                [['type' => 'quick_reply', 'text' => 'Eid Mubarak']],
                '#047857', '#F59E0B', '🌙', 'Eid Mubarak'),
            self::row('ramadan_kareem', 'festival', 'marketing',
                'Hello {{name}}, Ramadan Kareem. We wish you a blessed month of reflection, kindness, and togetherness.',
                'Ramadan Kareem',
                [['type' => 'quick_reply', 'text' => 'Thank you']],
                '#064E3B', '#FBBF24', '🕌', 'Ramadan Kareem'),
            self::row('holi_greetings', 'festival', 'marketing',
                'Hello {{name}}, wishing you a colourful Holi. Celebrate with {{discount}} off until {{expiry}} using code {{code}}.',
                'Happy Holi',
                [['type' => 'visit_website', 'text' => 'See colours', 'value' => 'https://example.com/holi']],
                '#DB2777', '#F59E0B', '🎨', 'Happy Holi'),
            self::row('mothers_day', 'festival', 'marketing',
                'Hello {{name}}, celebrate Mum with a gift she will love. Our Mother\'s Day picks are ready until {{expiry}}.',
                'Happy Mother\'s Day',
                [['type' => 'visit_website', 'text' => 'Shop gifts', 'value' => 'https://example.com/mothers-day']],
                '#BE185D', '#F9A8D4', '💐', 'Mother\'s Day gifts'),
            self::row('fathers_day', 'festival', 'marketing',
                'Hello {{name}}, make Father\'s Day easy. Find a gift for Dad and check out with code {{code}} before {{expiry}}.',
                'Happy Father\'s Day',
                [['type' => 'visit_website', 'text' => 'Shop for Dad', 'value' => 'https://example.com/fathers-day']],
                '#1E3A8A', '#60A5FA', '👔', 'Father\'s Day gifts'),
            self::row('thanksgiving', 'festival', 'marketing',
                'Hello {{name}}, happy Thanksgiving. We are grateful for you — enjoy a thank-you offer with code {{code}} until {{expiry}}.',
                'Happy Thanksgiving',
                [['type' => 'visit_website', 'text' => 'Claim offer', 'value' => 'https://example.com/thanksgiving']],
                '#B45309', '#FDE68A', '🦃', 'Thanksgiving thanks'),
            self::row('halloween_sale', 'festival', 'marketing',
                'Hello {{name}}, our Halloween sale is live. Treat yourself before {{expiry}} — no tricks, just deals.',
                'Halloween treats',
                [['type' => 'visit_website', 'text' => 'Shop Halloween', 'value' => 'https://example.com/halloween']],
                '#7C2D12', '#F97316', '🎃', 'Halloween sale'),
            self::row('independence_day', 'festival', 'marketing',
                'Hello {{name}}, happy Independence Day. Celebrate with us and enjoy a festive offer until {{expiry}}.',
                'Happy Independence Day',
                [['type' => 'visit_website', 'text' => 'Celebrate', 'value' => 'https://example.com/independence']],
                '#1D4ED8', '#DC2626', '🎆', 'Independence Day'),

            self::row('abandoned_cart', 'ecommerce', 'marketing',
                'Hi {{name}}, you left {{item}} in your cart. Complete your order in the next {{hours}} hours and we will hold it for you.',
                'Your cart is waiting',
                [['type' => 'visit_website', 'text' => 'Complete order', 'value' => 'https://example.com/cart']],
                '#B45309', '#F59E0B', '🛒', 'Abandoned cart'),
            self::row('order_confirmed', 'ecommerce', 'utility',
                'Hi {{name}}, we have confirmed order {{order_id}}. We will message you again when it ships.',
                'Order confirmed',
                [['type' => 'quick_reply', 'text' => 'View order']],
                '#166534', '#22C55E', '🧾', 'Order confirmed'),
            self::row('payment_received', 'ecommerce', 'utility',
                'Hi {{name}}, we received your payment of {{amount}} for order {{order_id}}. Thank you.',
                'Payment received',
                [['type' => 'quick_reply', 'text' => 'Thanks']],
                '#0F766E', '#5EEAD4', '💳', 'Payment received'),
            self::row('refund_processed', 'ecommerce', 'utility',
                'Hi {{name}}, your refund of {{amount}} for order {{order_id}} has been processed. It should appear in {{days}} days.',
                'Refund processed',
                [['type' => 'quick_reply', 'text' => 'Got it']],
                '#1E40AF', '#93C5FD', '↩️', 'Refund processed'),
            self::row('back_in_stock', 'ecommerce', 'marketing',
                'Hi {{name}}, {{item}} is back in stock. Grab it before it sells out again.',
                'Back in stock',
                [['type' => 'visit_website', 'text' => 'Buy now', 'value' => 'https://example.com/product']],
                '#6D28D9', '#C4B5FD', '✨', 'Back in stock'),
            self::row('flash_sale_tonight', 'ecommerce', 'marketing',
                'Hi {{name}}, tonight only: up to {{discount}} off until midnight. Do not miss these picks.',
                'Flash sale tonight',
                [['type' => 'visit_website', 'text' => 'Shop the flash', 'value' => 'https://example.com/flash']],
                '#9F1239', '#FB7185', '⚡', 'Flash sale'),
            self::row('review_request', 'ecommerce', 'marketing',
                'Hi {{name}}, how was order {{order_id}}? A quick review helps others and takes less than a minute.',
                'How did we do',
                [['type' => 'visit_website', 'text' => 'Leave a review', 'value' => 'https://example.com/review']],
                '#0F766E', '#99F6E4', '⭐', 'Ask for a review'),
            self::row('cod_confirmation', 'ecommerce', 'utility',
                'Hi {{name}}, please confirm cash on delivery for order {{order_id}} totaling {{amount}}. Reply YES to confirm.',
                'Confirm COD order',
                [['type' => 'quick_reply', 'text' => 'YES'], ['type' => 'quick_reply', 'text' => 'Cancel']],
                '#B45309', '#FBBF24', '💵', 'COD confirmation'),

            self::row('exam_reminder', 'education', 'utility',
                'Hi {{name}}, your {{course}} exam is on {{date}} at {{time}}. Location: {{venue}}. Please bring your ID.',
                'Exam reminder',
                [['type' => 'quick_reply', 'text' => 'I am ready']],
                '#1E3A8A', '#60A5FA', '📝', 'Exam reminder'),
            self::row('assignment_due', 'education', 'utility',
                'Hi {{name}}, your {{course}} assignment is due on {{date}} at {{time}}. Submit it on the student portal.',
                'Assignment due',
                [['type' => 'visit_website', 'text' => 'Submit work', 'value' => 'https://example.com/assignments']],
                '#7C2D12', '#F59E0B', '📎', 'Assignment due'),
            self::row('fee_reminder', 'education', 'utility',
                'Hi {{name}}, a fee of {{amount}} for {{course}} is due by {{date}}. Pay on time to keep your place.',
                'Fee reminder',
                [['type' => 'visit_website', 'text' => 'Pay now', 'value' => 'https://example.com/fees']],
                '#9F1239', '#FCA5A5', '💰', 'Fee reminder'),
            self::row('certificate_ready', 'education', 'utility',
                'Hi {{name}}, your certificate for {{course}} is ready. Download it from your student account.',
                'Certificate ready',
                [['type' => 'visit_website', 'text' => 'Download', 'value' => 'https://example.com/certificate']],
                '#134E4A', '#5EEAD4', '🏅', 'Certificate ready'),

            self::row('lab_results_ready', 'healthcare', 'utility',
                'Hi {{name}}, your lab results from {{date}} are ready. Please book a follow-up with {{doctor}} to review them.',
                'Lab results ready',
                [['type' => 'quick_reply', 'text' => 'Book follow-up']],
                '#0F766E', '#99F6E4', '🔬', 'Lab results ready'),
            self::row('follow_up_visit', 'healthcare', 'utility',
                'Hi {{name}}, please book your follow-up visit with {{doctor}} after {{date}}. Reply and we will help you pick a slot.',
                'Book a follow-up',
                [['type' => 'quick_reply', 'text' => 'Book now']],
                '#1D4ED8', '#93C5FD', '🗓️', 'Follow-up visit'),
            self::row('vaccination_reminder', 'healthcare', 'utility',
                'Hi {{name}}, this is a reminder that {{patient}} is due for {{vaccine}} on {{date}}. Reply to confirm the appointment.',
                'Vaccination due',
                [['type' => 'quick_reply', 'text' => 'Confirm']],
                '#166534', '#86EFAC', '💉', 'Vaccination reminder'),
            self::row('clinic_hours', 'healthcare', 'utility',
                'Hi {{name}}, {{clinic}} is open {{hours}} on {{date}}. Reply if you need to reschedule your visit.',
                'Clinic hours',
                [['type' => 'quick_reply', 'text' => 'Thanks']],
                '#134E4A', '#5EEAD4', '🏥', 'Clinic hours today'),

            self::row('booking_confirmed', 'utility', 'utility',
                'Hi {{name}}, your booking at {{company}} is confirmed for {{date}} at {{time}}. See you then.',
                'Booking confirmed',
                [['type' => 'quick_reply', 'text' => 'Add to calendar']],
                '#1B4B3D', '#037D66', '📌', 'Booking confirmed'),
            self::row('payment_due', 'utility', 'utility',
                'Hi {{name}}, a payment of {{amount}} is due on {{date}}. Pay on time to avoid a late fee.',
                'Payment due',
                [['type' => 'visit_website', 'text' => 'Pay now', 'value' => 'https://example.com/pay']],
                '#9A3412', '#FDBA74', '📅', 'Payment due'),
            self::row('feedback_request', 'utility', 'marketing',
                'Hi {{name}}, we would love your feedback on {{company}}. Two minutes of your time helps us improve.',
                'Share your feedback',
                [['type' => 'visit_website', 'text' => 'Give feedback', 'value' => 'https://example.com/feedback']],
                '#1E3A8A', '#93C5FD', '💬', 'Feedback request'),
            self::row('support_ticket_update', 'utility', 'utility',
                'Hi {{name}}, an update on ticket {{ticket_id}}: {{status}}. Reply here if you still need help.',
                'Support update',
                [['type' => 'quick_reply', 'text' => 'Still need help']],
                '#334155', '#94A3B8', '🎧', 'Support ticket update'),
            self::row('birthday_wish', 'utility', 'marketing',
                'Happy birthday {{name}}. We saved a gift for you — use code {{code}} before {{expiry}}.',
                'Happy birthday',
                [['type' => 'visit_website', 'text' => 'Unwrap gift', 'value' => 'https://example.com/birthday']],
                '#BE185D', '#F9A8D4', '🎂', 'Birthday wish'),
            self::row('delivery_delayed', 'ecommerce', 'utility',
                'Hi {{name}}, delivery for order {{order_id}} is running late. The new window is {{date}}. Sorry for the wait.',
                'Delivery update',
                [['type' => 'quick_reply', 'text' => 'Okay']],
                '#9A3412', '#FDBA74', '🚚', 'Delivery delayed'),
        ];
    }

    /**
     * @param  list<array{type: string, text: string, value?: string}>  $buttons
     * @return array<string, mixed>
     */
    private static function row(
        string $slug,
        string $category,
        string $metaCategory,
        string $body,
        string $header,
        array $buttons,
        string $colorFrom,
        string $colorTo,
        string $emoji,
        ?string $title = null,
    ): array {
        $preview = preg_replace('/\s+/', ' ', $body) ?? $body;

        return [
            'slug'           => $slug,
            'title'          => $title ?: str_replace('_', ' ', $slug),
            'category'       => $category,
            'category_label' => self::CATEGORIES[$category] ?? $category,
            'meta_category'  => $metaCategory,
            'language'       => 'en_US',
            'header'         => $header,
            'body'           => $body,
            'footer'         => $metaCategory === 'marketing' ? 'Reply STOP to unsubscribe' : '',
            'buttons'        => $buttons,
            'preview'        => mb_strlen($preview) > 140 ? mb_substr($preview, 0, 137).'…' : $preview,
            'gradient'       => 'from-['.$colorFrom.'] to-['.$colorTo.']',
            'color_from'     => $colorFrom,
            'color_to'       => $colorTo,
            'emoji'          => $emoji,
        ];
    }
}
