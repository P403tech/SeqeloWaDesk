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
    ): array {
        $preview = preg_replace('/\s+/', ' ', $body) ?? $body;

        return [
            'slug'           => $slug,
            'title'          => str_replace('_', ' ', $slug),
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
