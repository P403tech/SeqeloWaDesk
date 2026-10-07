<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Single source of truth for the admin "Feature Toggles" page.
 *
 * Every user-facing feature/card is listed here once, grouped. The admin page
 * (/admin/settings/features) renders this as on/off switches and stores one
 * `feature_show_<key>` boolean per feature. The three navigation surfaces —
 * the sidebar rail (UserNav::groups), the classic top-bar (header.blade), and
 * the /more overflow grid — all ask FeatureRegistry::visible($key) before
 * showing an item, so turning a switch OFF hides that feature everywhere.
 *
 * Default is ON: a feature only disappears once an admin explicitly turns it
 * off, so a fresh install / upgrade shows exactly what it did before.
 *
 * This is a VISIBILITY layer only. It sits ON TOP of the existing gates
 * (channel `*_enabled` availability, per-plan `feature` locking, workspace
 * role tiers) and never replaces them — an item hidden by any of those stays
 * hidden regardless of this switch.
 */
class FeatureRegistry
{
    /** Prefix for every stored flag: feature_show_<key> (bool, default true). */
    public const FLAG_PREFIX = 'feature_show_';

    /**
     * The catalogue, grouped in display order. Each item:
     *   key   — matches the nav item key (UserNav + header) — the gate handle.
     *   label — shown on the admin toggle page.
     *   path  — the primary URL path of the feature, used to hide its card on
     *           the /more grid (client-side match on the card's href).
     */
    public static function groups(): array
    {
        return [
            'Overview' => [
                // Dashboard hides from nav if switched off, but is never route
                // -blocked — it is the fallback landing page (blocking it would
                // strand the user), so 'block' => false.
                ['key' => 'dashboard',      'label' => 'Dashboard',            'path' => '/dashboard', 'block' => false],
                ['key' => 'analytics',      'label' => 'Analytics',            'path' => '/analytics'],
                ['key' => 'notifications',  'label' => 'Notifications',        'path' => '/notifications'],
            ],
            'Inbox & Chat' => [
                ['key' => 'team-inbox',     'label' => 'Team Inbox',           'path' => '/team-inbox'],
                ['key' => 'chat',           'label' => 'Quick Send (Chat)',    'path' => '/chat'],
                ['key' => 'call-logs',      'label' => 'Call Logs',            'path' => '/call-logs'],
                ['key' => 'team-members',   'label' => 'Team Members',         'path' => '/team-inbox/members'],
            ],
            'Audience' => [
                ['key' => 'contacts',       'label' => 'Contacts',             'path' => '/contacts'],
                ['key' => 'attributes',     'label' => 'Attributes',           'path' => '/attributes'],
                ['key' => 'lead-finder',    'label' => 'Lead Finder',          'path' => '/lead-finder'],
                ['key' => 'deals',          'label' => 'Deals',                'path' => '/deals'],
            ],
            'Campaigns' => [
                ['key' => 'wa-campaigns',   'label' => 'Campaigns',            'path' => '/wa-campaigns'],
                ['key' => 'broadcasts',     'label' => 'Broadcasts',           'path' => '/broadcasts'],
                ['key' => 'scheduled',      'label' => 'Scheduled Messages',   'path' => '/scheduled'],
                ['key' => 'drip',           'label' => 'Drip Campaigns',       'path' => '/drip-campaigns'],
                ['key' => 'templates',      'label' => 'Templates',            'path' => '/templates'],
                ['key' => 'wa-forms',       'label' => 'WhatsApp Forms',       'path' => '/wa-forms'],
                ['key' => 'waba-groups',    'label' => 'WhatsApp Groups',      'path' => '/waba-groups'],
                ['key' => 'message-history','label' => 'Message History',      'path' => '/message-history'],
            ],
            'Automation' => [
                ['key' => 'flows',          'label' => 'Flows (Chatbot)',      'path' => '/flows'],
                ['key' => 'flow-analytics', 'label' => 'Flow Analytics',       'path' => '/flows/analytics'],
                ['key' => 'ai-assistants',  'label' => 'AI Assistants',        'path' => '/ai-assistants'],
                ['key' => 'auto-reply',     'label' => 'Auto Reply',           'path' => '/auto-reply'],
                ['key' => 'appointments',   'label' => 'Appointments',         'path' => '/appointments'],
                ['key' => 'ai-training',    'label' => 'AI Training',          'path' => '/ai-training'],
                ['key' => 'ai-usage',       'label' => 'AI Usage',             'path' => '/ai-usage'],
                ['key' => 'chatbot-widgets','label' => 'Chatbot Widgets',      'path' => '/chatbot-widgets'],
                ['key' => 'warmer',         'label' => 'WhatsApp Warmer',      'path' => '/warmer'],
            ],
            // Channel pages use feature_show_* so closing an app hides it from
            // customers without wiping Channel Settings engine flags.
            'Channels' => [
                ['key' => 'devices',        'label' => 'Channels (Devices)',   'path' => '/devices'],
                ['key' => 'metaads',        'label' => 'Meta Ads',             'path' => '/meta-ads'],
                ['key' => 'lead-ads',       'label' => 'Lead Ads',             'path' => '/lead-ads'],
                ['key' => 'social-posts',   'label' => 'Social Posts',         'path' => '/social/posts'],
                ['key' => 'social-calendar','label' => 'Social Calendar',      'path' => '/social/calendar'],
                ['key' => 'instagram-posts','label' => 'Instagram Posts',      'path' => '/instagram/posts'],
                ['key' => 'facebook-posts', 'label' => 'Facebook (channel)',   'path' => '/facebook/posts'],
                ['key' => 'facebook-setup', 'label' => 'Messenger Setup',      'path' => '/facebook/setup'],
                ['key' => 'facebook-broadcasts',    'label' => 'Facebook Broadcasts',   'path' => '/facebook/broadcasts'],
                ['key' => 'facebook-comment-rules', 'label' => 'FB Comment Auto-reply', 'path' => '/facebook/comment-rules'],
                ['key' => 'tiktok-accounts','label' => 'TikTok (channel)',     'path' => '/tiktok/accounts'],
                ['key' => 'tiktok-posts',   'label' => 'TikTok Posts',         'path' => '/tiktok/posts'],
                ['key' => 'telegram',       'label' => 'Telegram',             'path' => '/telegram'],
                ['key' => 'sms',            'label' => 'SMS',                  'path' => '/sms'],
                ['key' => 'line',           'label' => 'LINE',                 'path' => '/line'],
                ['key' => 'viber',          'label' => 'Viber',                'path' => '/viber'],
                ['key' => 'wechat',         'label' => 'WeChat',               'path' => '/wechat'],
                ['key' => 'email',          'label' => 'Email',                'path' => '/email'],
                ['key' => 'threads-posts',  'label' => 'Threads',              'path' => '/threads/posts'],
                ['key' => 'openaiads',      'label' => 'OpenAI Ads',           'path' => '/openai-ads'],
                ['key' => 'wa-links',       'label' => 'WhatsApp Link Generator', 'path' => '/wa-links'],
            ],
            'Sales & CRM' => [
                ['key' => 'crm-dashboard',  'label' => 'CRM Dashboard',        'path' => '/crm'],
                ['key' => 'sdr',            'label' => 'AI SDR',               'path' => '/sdr'],
                ['key' => 'ai-crm',         'label' => 'AI CRM',               'path' => '/ai-crm'],
                ['key' => 'companies',      'label' => 'Companies',            'path' => '/companies'],
                ['key' => 'payments',       'label' => 'Payments',             'path' => '/payments'],
                ['key' => 'tasks',          'label' => 'Tasks',                'path' => '/tasks'],
                ['key' => 'projects',       'label' => 'Projects',             'path' => '/projects'],
                ['key' => 'proposals',      'label' => 'Proposals',            'path' => '/proposals'],
                ['key' => 'estimates',      'label' => 'Estimates',            'path' => '/estimates'],
                ['key' => 'calendar',       'label' => 'Calendar',             'path' => '/calendar'],
            ],
            'Store' => [
                ['key' => 'store',          'label' => 'Store',                'path' => '/store'],
                ['key' => 'catalog',        'label' => 'Catalog',              'path' => '/catalog'],
                ['key' => 'shopify',        'label' => 'Shopify',              'path' => '/shopify'],
                ['key' => 'woocommerce',    'label' => 'WooCommerce',          'path' => '/woocommerce'],
            ],
            'Developer' => [
                ['key' => 'integrations',   'label' => 'Integrations',         'path' => '/integrations'],
                ['key' => 'google-account', 'label' => 'Google Account',       'path' => '/google-account'],
                ['key' => 'developers',     'label' => 'Developers / API',     'path' => '/developers'],
                ['key' => 'webhooks',       'label' => 'Webhooks',             'path' => '/webhooks'],
                ['key' => 'n8n',            'label' => 'n8n Connector',        'path' => '/n8n'],
            ],
            'Help' => [
                ['key' => 'support',        'label' => 'Support',              'path' => '/support'],
                ['key' => 'guidebook',      'label' => 'Guidebook',            'path' => '/guidebook'],
                ['key' => 'activity-log',   'label' => 'Activity Log',         'path' => '/activity-log'],
            ],
            // Sign-in providers. These reuse the EXISTING social-login flags
            // (also set on the Social Login settings page), so toggling here is
            // the same switch — one-stop control. They have no dashboard 'path'
            // (login is pre-auth), so the route-block middleware skips them; the
            // social-login system already hides the button + rejects the OAuth
            // route when its flag is off. Default OFF (opt-in, like today).
            'Login & Sign-in' => [
                ['key' => 'facebook-login', 'label' => 'Facebook Login', 'flag' => 'social_facebook_enabled', 'default' => false],
                ['key' => 'google-login',   'label' => 'Google Login',   'flag' => 'social_google_enabled',   'default' => false],
            ],
        ];
    }

    /** Flat list of every registry item (across all groups). */
    public static function all(): array
    {
        $out = [];
        foreach (self::groups() as $items) {
            foreach ($items as $it) {
                $out[] = $it;
            }
        }

        return $out;
    }

    /**
     * Nav / header keys that refer to the same registry row under another name.
     * Unknown keys used to skip the gate (always visible) — that is why CRM
     * stayed in the customer header after admin closed "CRM Dashboard".
     */
    public static function resolveKey(string $key): string
    {
        return match ($key) {
            'crm' => 'crm-dashboard',
            'openai-ads' => 'openaiads',
            'meta-ads' => 'metaads',
            default => $key,
        };
    }

    /** The item for a key, or null. */
    public static function find(string $key): ?array
    {
        $key = self::resolveKey($key);
        foreach (self::all() as $it) {
            if (($it['key'] ?? null) === $key) {
                return $it;
            }
        }

        return null;
    }

    /** The SystemSetting key that stores this item's on/off state. */
    public static function flagOf(array $it): string
    {
        return (string) ($it['flag'] ?? self::FLAG_PREFIX . $it['key']);
    }

    /** Customer-visibility flag — never the engine *_enabled row. */
    public static function showFlagOf(array $it): string
    {
        return self::FLAG_PREFIX.$it['key'];
    }

    /** The default (when the flag was never written): ON unless the item says otherwise. */
    public static function defaultOf(array $it): bool
    {
        return (bool) ($it['default'] ?? true);
    }

    /**
     * The registry feature that owns a request PATH, for route-blocking — the
     * LONGEST matching 'path' prefix (so /flows/analytics resolves to the
     * flow-analytics feature, not flows). Items without a 'path' (login) or
     * marked 'block' => false (dashboard) are never returned.
     */
    public static function matchPath(string $path): ?array
    {
        $path = '/' . ltrim(rtrim($path, '/'), '/');
        $best = null;
        $bestLen = -1;
        foreach (self::all() as $it) {
            if (($it['block'] ?? true) === false || empty($it['path'])) {
                continue;
            }
            $p = rtrim($it['path'], '/');
            if ($path === $p || str_starts_with($path, $p . '/')) {
                if (strlen($p) > $bestLen) {
                    $best = $it;
                    $bestLen = strlen($p);
                }
            }
        }

        return $best;
    }

    /** True when this URL may appear on the customer dashboard. */
    public static function hrefVisible(string $href): bool
    {
        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        $path = '/' . ltrim((string) $path, '/');
        $feature = self::matchPath($path);
        if ($feature === null) {
            return true;
        }

        return self::visible((string) $feature['key']);
    }

    /** Flat list of every feature key in the registry. */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::groups() as $items) {
            foreach ($items as $it) {
                $keys[] = $it['key'];
            }
        }

        return $keys;
    }

    /** True if $key is a feature we manage (unknown keys are never gated). */
    public static function isFeature(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** Per-request memo so one nav render doesn't re-read the same flag N times. */
    private static array $memo = [];

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /**
     * Is this feature visible? Unknown keys (anything not in the registry) are
     * always visible, so this can be dropped into any nav filter safely.
     */
    public static function visible(string $key): bool
    {
        $it = self::find($key);
        if ($it === null) {
            return true; // unknown keys are never gated
        }
        $memoKey = (string) $it['key'];
        if (! array_key_exists($memoKey, self::$memo)) {
            $stored = SystemSetting::get(self::showFlagOf($it), null);
            if ($stored === null) {
                // Older Feature Toggles saved the engine flag (`instagram_enabled`
                // etc). Honour that OFF so a previously closed app stays closed.
                $stored = SystemSetting::get(self::flagOf($it), self::defaultOf($it));
            }
            self::$memo[$memoKey] = (bool) $stored;
        }

        return self::$memo[$memoKey];
    }

    /** Current on/off state for every feature — powers the admin toggle grid. */
    public static function states(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::visible($key);
        }

        return $out;
    }

    /**
     * URL paths of every DISABLED feature — handed to user-more-index.js so it
     * can hide the matching cards on the /more grid (which is inline markup
     * with no central array to filter server-side).
     */
    public static function hiddenPaths(): array
    {
        $paths = [];
        foreach (self::all() as $it) {
            if (! empty($it['path']) && ! self::visible($it['key'])) {
                $paths[] = $it['path'];
            }
        }

        return array_values($paths);
    }

    /**
     * Plan feature key that still gates the route after admin Features
     * leaves the app visible. Null = no extra plan gate in this map.
     */
    public static function planGate(string $key): ?string
    {
        return match (self::resolveKey($key)) {
            'ai-training' => 'access_ai_chat_assistant',
            'chatbot-widgets' => 'access_chatbot_widgets',
            'ai-assistants' => 'access_ai_agents',
            'wa-forms' => 'access_wa_forms',
            'wa-links' => 'access_wa_links',
            default => null,
        };
    }

    /**
     * What customers currently see vs cannot see, from saved Features.
     * Login-only flags (no path) are omitted.
     *
     * @return array{hidden: list<array{key:string,label:string,path:string,plan:?string}>, shown: list<array{key:string,label:string,path:string,plan:?string}>}
     */
    public static function customerPreview(): array
    {
        $hidden = [];
        $shown = [];
        foreach (self::all() as $it) {
            if (empty($it['path'])) {
                continue;
            }
            $row = [
                'key'   => (string) $it['key'],
                'label' => (string) $it['label'],
                'path'  => (string) $it['path'],
                'plan'  => self::planGate((string) $it['key']),
            ];
            if (self::visible((string) $it['key'])) {
                $shown[] = $row;
            } else {
                $hidden[] = $row;
            }
        }

        return ['hidden' => $hidden, 'shown' => $shown];
    }
}
