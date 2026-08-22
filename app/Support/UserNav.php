<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Single source of truth for the USER dashboard navigation used by the
 * sidebar layout. The classic top-bar header keeps its own inline list
 * (untouched) — this class powers the alternative sidebar shell, where the
 * whole catalogue is shown grouped (there is NO "/more" overflow page in
 * sidebar mode; every item lives directly in the rail, like the admin side).
 *
 * Nothing here changes the topbar layout, so existing pages cannot break.
 */
class UserNav
{
    /**
     * Effective user-dashboard layout chosen by the admin.
     * 'topbar' (default, current header + /more) | 'sidebar' (admin-style rail).
     */
    public static function layout(): string
    {
        $v = (string) SystemSetting::get('user_dashboard_layout', 'topbar');

        return $v === 'sidebar' ? 'sidebar' : 'topbar';
    }

    /** Workspace-role → tier rank for the current user (mirrors the header gate). */
    private static function userRank(): int
    {
        $wsRole = auth()->user()?->workspaceRole();
        $minTier = match ($wsRole) {
            'owner', 'admin' => 'admin',
            'manager'        => 'manager',
            'agent', 'viewer' => 'agent',
            default          => 'admin',
        };

        return ['agent' => 1, 'manager' => 2, 'admin' => 3][$minTier] ?? 3;
    }

    /**
     * Grouped navigation for the sidebar. Each group = ['label' => …,
     * 'items' => [ [key,label,href,icon,sw,tier,feature?], … ]].
     * Items above the user's tier are filtered out. The whole catalogue is
     * present (no `promo`/`more` hiding) — that is the point of sidebar mode.
     */
    public static function groups(): array
    {
        $rank = ['agent' => 1, 'manager' => 2, 'admin' => 3];
        $userRank = self::userRank();

        // Instagram is only reachable when the native add-on is enabled OR a
        // remote Instaflow is connected (same gate the /more page uses). Items
        // tagged 'ig' => true are dropped when Instagram isn't available.
        $igAvailable = false;
        try {
            $igAvailable = \App\Services\Instaflow\InstaflowClient::fromSettings()->isConnected()
                || (bool) SystemSetting::get('instagram_enabled', false);
        } catch (\Throwable $e) {
            $igAvailable = (bool) SystemSetting::get('instagram_enabled', false);
        }

        // Facebook channel availability — items tagged 'fb' => true drop when off.
        $fbAvailable = (bool) SystemSetting::get('facebook_enabled', false);

        // TikTok channel availability — items tagged 'tt' => true drop when off.
        $ttAvailable = (bool) SystemSetting::get('tiktok_enabled', false);

        // Telegram channel availability — items tagged 'tg' => true drop when off.
        $tgAvailable = (bool) SystemSetting::get('telegram_enabled', false);

        // SMS channel availability — items tagged 'sms' => true drop when off.
        $smsAvailable = (bool) SystemSetting::get('sms_enabled', false);

        $catalogue = [
            [
                'label' => __('Overview'),
                'items' => [
                    ['key' => 'dashboard', 'tier' => 'manager', 'href' => url('/dashboard'), 'label' => __('Dashboard'),
                        'icon' => '<rect x="2" y="2" width="5" height="6" rx="1"/><rect x="9" y="2" width="5" height="3" rx="1"/><rect x="9" y="7" width="5" height="7" rx="1"/><rect x="2" y="10" width="5" height="4" rx="1"/>', 'sw' => 1.6],
                    ['key' => 'analytics', 'tier' => 'manager', 'href' => url('/analytics'), 'label' => __('Analytics'),
                        'icon' => '<path d="M2 13V3M2 13h12M5 11V7M8 11V4M11 11V8"/>', 'sw' => 1.6],
                ],
            ],
            [
                'label' => __('Inbox & Chat'),
                'items' => [
                    ['key' => 'team-inbox', 'tier' => 'admin', 'href' => url('/team-inbox'), 'label' => __('Unified Inbox'),
                        'icon' => '<rect x="2" y="3" width="12" height="9" rx="1.5"/><path d="M2 6h12M5 9h2.5"/>', 'sw' => 1.5],
                    ['key' => 'chat', 'tier' => 'manager', 'href' => url('/chat'), 'label' => __('Live Chat'),
                        'icon' => '<path d="M2 4.5A2.5 2.5 0 0 1 4.5 2h7A2.5 2.5 0 0 1 14 4.5v4A2.5 2.5 0 0 1 11.5 11H6l-3 2v-2A2.5 2.5 0 0 1 2 8.5v-4Z"/>', 'sw' => 1.5],
                    ['key' => 'call-logs', 'tier' => 'admin', 'href' => url('/call-logs'), 'label' => __('Call Logs'),
                        'icon' => '<path d="M3 3h2l1.4 3.4L5 8a8 8 0 0 0 3 3l1.6-1.4L13 11v2a1 1 0 0 1-1 1A11 11 0 0 1 2 4a1 1 0 0 1 1-1Z"/>', 'sw' => 1.3],
                ],
            ],
            [
                'label' => __('Audience'),
                'items' => [
                    ['key' => 'contacts', 'tier' => 'manager', 'href' => url('/contacts'), 'label' => __('Contacts'),
                        'icon' => '<circle cx="6" cy="5" r="2.2"/><path d="M2.5 13a3.5 3.5 0 0 1 7 0"/><path d="M11 4.7a2 2 0 0 1 0 3.6M13.5 13a3.4 3.4 0 0 0-2-3.1"/>', 'sw' => 1.5],
                    ['key' => 'lead-finder', 'tier' => 'manager', 'href' => url('/lead-finder'), 'label' => __('Lead Finder'),
                        'icon' => '<path d="M8 1.5c-2.8 0-5 2.1-5 4.9 0 3.4 5 8.1 5 8.1s5-4.7 5-8.1c0-2.8-2.2-4.9-5-4.9Z"/><circle cx="8" cy="6.3" r="1.7"/>', 'sw' => 1.5, 'feature' => 'access_lead_finder'],
                    ['key' => 'deals', 'tier' => 'admin', 'href' => url('/deals'), 'label' => __('Deals'),
                        'icon' => '<path d="M8 2.5l5.5 4.2L11.4 13H4.6L2.5 6.7 8 2.5Z"/>', 'sw' => 1.5, 'feature' => 'access_sales_pipeline'],
                ],
            ],
            [
                'label' => __('Campaigns'),
                'items' => [
                    ['key' => 'wa-campaigns', 'tier' => 'admin', 'href' => url('/wa-campaigns'), 'label' => __('Campaigns'),
                        'icon' => '<path d="M3 4.5A2.5 2.5 0 0 1 5.5 2h5A2.5 2.5 0 0 1 13 4.5v4A2.5 2.5 0 0 1 10.5 11H8l-3.5 2v-2A2.5 2.5 0 0 1 2 8.5v-4Z"/><path d="M5.5 6.5h5M5.5 8.5h3"/>', 'sw' => 1.5, 'feature' => 'campaign'],
                    ['key' => 'broadcasts', 'tier' => 'admin', 'href' => url('/broadcasts'), 'label' => __('Broadcasts'),
                        'icon' => '<path d="M2 6v4l8 3V3L2 6Z"/><path d="M10 5l3-1v8l-3-1"/>', 'sw' => 1.5],
                    ['key' => 'scheduled', 'tier' => 'admin', 'href' => url('/scheduled'), 'label' => __('Scheduled'),
                        'icon' => '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1"/>', 'sw' => 1.5],
                    ['key' => 'templates', 'tier' => 'manager', 'href' => url('/templates'), 'label' => __('Templates'),
                        'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="1.5"/><path d="M2.5 6h11M6 13.5V6"/>', 'sw' => 1.5, 'feature' => 'template'],
                    ['key' => 'message-history', 'tier' => 'manager', 'href' => url('/message-history'), 'label' => __('History'),
                        'icon' => '<path d="M3 3v4h4"/><path d="M3.5 7a6 6 0 1 1 .3 2.4"/><path d="M8 5v3l2 1"/>', 'sw' => 1.5],
                ],
            ],
            [
                'label' => __('Automation'),
                'items' => [
                    ['key' => 'flows', 'tier' => 'admin', 'href' => url('/flows'), 'label' => __('Flows'),
                        'icon' => '<circle cx="3.5" cy="8" r="1.8"/><circle cx="12.5" cy="3.5" r="1.8"/><circle cx="12.5" cy="12.5" r="1.8"/><path d="M5 7l6-3M5 9l6 3"/>', 'sw' => 1.5, 'feature' => 'autoflow'],
                    ['key' => 'ai-assistants', 'tier' => 'admin', 'href' => url('/ai-assistants'), 'label' => __('AI Assistants'),
                        'icon' => '<rect x="3" y="4" width="10" height="8" rx="2"/><circle cx="6.2" cy="8" r="0.9"/><circle cx="9.8" cy="8" r="0.9"/><path d="M8 2v2"/>', 'sw' => 1.4, 'feature' => 'access_ai_agents'],
                    ['key' => 'auto-reply', 'tier' => 'admin', 'href' => url('/auto-reply'), 'label' => __('Auto-reply'),
                        'icon' => '<path d="M6 3 2 7l4 4M2.5 7H9a4 4 0 0 1 4 4v1"/>', 'sw' => 1.5],
                    ['key' => 'appointments', 'tier' => 'admin', 'href' => url('/appointments'), 'label' => __('Appointments'),
                        'icon' => '<rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 1.5v2M11 1.5v2M5.5 9l1.3 1.3L10 8"/>', 'sw' => 1.5, 'feature' => 'access_appointment_booking'],
                    ['key' => 'ai-training', 'tier' => 'admin', 'href' => url('/ai-training'), 'label' => __('AI Training'),
                        'icon' => '<path d="M8 2l5 3v3c0 3-2.2 5-5 6-2.8-1-5-3-5-6V5l5-3Z"/>', 'sw' => 1.5, 'feature' => 'access_ai_training'],
                    ['key' => 'ai-usage', 'tier' => 'manager', 'href' => url('/ai-usage'), 'label' => __('AI Usage'),
                        'icon' => '<path d="M2 13V3M2 13h12M5 11V7M8 11V4.5M11 11V8.5"/>', 'sw' => 1.6, 'feature' => 'access_ai_agents'],
                    ['key' => 'chatbot-widgets', 'tier' => 'admin', 'href' => url('/chatbot-widgets'), 'label' => __('Chat Widget'),
                        'icon' => '<rect x="3" y="3" width="10" height="8" rx="2"/><path d="M6 13l2-2h3"/><circle cx="6.5" cy="7" r="0.8"/><circle cx="9.5" cy="7" r="0.8"/>', 'sw' => 1.4, 'feature' => 'access_chatbot_widgets'],
                    ['key' => 'warmer', 'tier' => 'admin', 'href' => url('/warmer'), 'label' => __('Warmer'),
                        'icon' => '<path d="M8 2c2 2 1 4 0 5s-2 3 0 5"/><path d="M5 7c1 1 .5 2 0 2.6M11 7c-1 1-.5 2 0 2.6"/>', 'sw' => 1.4],
                ],
            ],
            [
                'label' => __('Channels'),
                'items' => [
                    ['key' => 'devices', 'tier' => 'admin', 'href' => url('/devices'), 'label' => __('Channels'),
                        'icon' => '<circle cx="8" cy="8" r="2.2"/><circle cx="3" cy="3.5" r="1.4"/><circle cx="13" cy="3.5" r="1.4"/><circle cx="3" cy="12.5" r="1.4"/><circle cx="13" cy="12.5" r="1.4"/><path d="M4.2 4.6 6.4 6.7M11.8 4.6 9.6 6.7M4.2 11.4 6.4 9.3M11.8 11.4 9.6 9.3"/>', 'sw' => 1.5],
                    ['key' => 'metaads', 'tier' => 'admin', 'href' => url('/meta-ads'), 'label' => __('Meta Ads'),
                        'icon' => '<path d="M2 4l12-2v12L2 12V4Z"/>', 'sw' => 1.5, 'feature' => 'access_ctwa'],
                    ['key' => 'social-posts', 'tier' => 'admin', 'href' => url('/social/posts'), 'label' => __('All Posts'), 'social' => true,
                        'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="2.5"/><path d="M2.5 6.5h11M5.5 2.5v11"/>', 'sw' => 1.5],
                    ['key' => 'social-calendar', 'tier' => 'admin', 'href' => url('/social/calendar'), 'label' => __('Calendar'), 'social' => true,
                        'icon' => '<rect x="2" y="3" width="12" height="11" rx="1.6"/><path d="M2 6.5h12M5 1.8v2.4M11 1.8v2.4"/>', 'sw' => 1.5],
                    ['key' => 'instagram-posts', 'tier' => 'admin', 'href' => url('/instagram/posts'), 'label' => __('Instagram Posts'), 'ig' => true,
                        'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="3.2"/><circle cx="8" cy="8" r="2.6"/><circle cx="11.3" cy="4.7" r="0.7" fill="currentColor" stroke="none"/>', 'sw' => 1.4],
                    ['key' => 'facebook-posts', 'tier' => 'admin', 'href' => url('/facebook/posts'), 'label' => __('Facebook Posts'), 'fb' => true,
                        'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="3"/><path d="M9.6 5.2H8.5c-.7 0-1.15.42-1.15 1.15V8H9.1l-.3 1.85H7.35V13"/>', 'sw' => 1.5],
                    ['key' => 'facebook-setup', 'tier' => 'admin', 'href' => url('/facebook/setup'), 'label' => __('Messenger Setup'), 'fb' => true,
                        'icon' => '<path d="M2 4.5A2.5 2.5 0 0 1 4.5 2h7A2.5 2.5 0 0 1 14 4.5v4A2.5 2.5 0 0 1 11.5 11H6l-3 2v-2A2.5 2.5 0 0 1 2 8.5v-4Z"/><path d="M5 5.2h6M5 7.2h4"/>', 'sw' => 1.5],
                    ['key' => 'facebook-broadcasts', 'tier' => 'admin', 'href' => url('/facebook/broadcasts'), 'label' => __('Messenger Broadcasts'), 'fb' => true, 'feature' => 'access_facebook',
                        'icon' => '<path d="M2 6.5 13 3v10L2 9.5zM2 6.5v3M5 10v2.5a1 1 0 0 0 1 1h1"/>', 'sw' => 1.4],
                    ['key' => 'tiktok-accounts', 'tier' => 'admin', 'href' => url('/tiktok/accounts'), 'label' => __('TikTok'), 'tt' => true,
                        'icon' => '<path d="M10.4 2.5v7.8a2 2 0 1 1-1.8-2v-1.7a3.7 3.7 0 1 0 3.2 3.66V6.1a4.7 4.7 0 0 0 2.7.86V5.3a2.8 2.8 0 0 1-2.6-2.8z" fill="currentColor" stroke="none"/>', 'sw' => 1.4],
                    ['key' => 'tiktok-posts', 'tier' => 'admin', 'href' => url('/tiktok/posts'), 'label' => __('TikTok Posts'), 'tt' => true,
                        'icon' => '<rect x="2.5" y="2.5" width="11" height="11" rx="3"/><path d="M6.5 5.5l4 2.5-4 2.5z"/>', 'sw' => 1.5],
                    ['key' => 'telegram', 'tier' => 'admin', 'href' => url('/telegram'), 'label' => __('Telegram'), 'feature' => 'access_telegram', 'tg' => true,
                        'icon' => '<path d="M14 2 2 7l3.5 1.2M14 2l-2 10.5-4-3M14 2 6.5 9.2M6.5 9.2 6 12.5l2-2"/>', 'sw' => 1.4],
                    ['key' => 'sms', 'tier' => 'admin', 'href' => url('/sms'), 'label' => __('SMS'), 'sms' => true,
                        'icon' => '<path d="M2 4.5h12v7H8l-3 2.5V11.5H2z"/>', 'sw' => 1.5],
                    ['key' => 'wa-links', 'tier' => 'manager', 'href' => url('/wa-links'), 'label' => __('WA Links'),
                        'icon' => '<path d="M6.5 9.5a3 3 0 0 0 4 0l2-2a3 3 0 0 0-4-4l-1 1"/><path d="M9.5 6.5a3 3 0 0 0-4 0l-2 2a3 3 0 0 0 4 4l1-1"/>', 'sw' => 1.4],
                ],
            ],
            [
                'label' => __('Developer'),
                'items' => [
                    ['key' => 'integrations', 'tier' => 'admin', 'href' => url('/integrations'), 'label' => __('Integrations'),
                        'icon' => '<rect x="2" y="6" width="5" height="5" rx="1"/><rect x="9" y="3" width="5" height="5" rx="1"/><path d="M7 8.5h2v-1"/>', 'sw' => 1.4],
                    ['key' => 'webhooks', 'tier' => 'admin', 'href' => url('/webhooks'), 'label' => __('Webhooks'),
                        'icon' => '<circle cx="5" cy="5" r="2"/><circle cx="11" cy="6" r="2"/><circle cx="7" cy="12" r="2"/><path d="M6 6.6l-1 3.4M9.4 7l-2 3.4M6.8 5.5h3"/>', 'sw' => 1.3, 'feature' => 'access_outbound_webhooks'],
                ],
            ],
            [
                'label' => __('Help'),
                'items' => [
                    ['key' => 'support', 'tier' => 'manager', 'href' => url('/support'), 'label' => __('Support'),
                        'icon' => '<circle cx="8" cy="8" r="6"/><path d="M6.2 6.4a2 2 0 1 1 2.9 1.8c-.6.4-1.1.8-1.1 1.4M8 12h.01"/>', 'sw' => 1.4],
                ],
            ],
        ];

        // Tier-filter each group; drop empty groups.
        $out = [];
        foreach ($catalogue as $group) {
            $items = array_values(array_filter($group['items'], function ($it) use ($rank, $userRank, $igAvailable, $fbAvailable, $ttAvailable, $tgAvailable, $smsAvailable) {
                if (($it['ig'] ?? false) && ! $igAvailable) {
                    return false;
                }
                // 'social' items (All Posts / Calendar) show if ANY posting channel is on.
                if (($it['social'] ?? false) && ! ($igAvailable || $fbAvailable || $ttAvailable)) {
                    return false;
                }
                if (($it['tt'] ?? false) && ! $ttAvailable) {
                    return false;
                }
                if (($it['fb'] ?? false) && ! $fbAvailable) {
                    return false;
                }
                if (($it['tg'] ?? false) && ! $tgAvailable) {
                    return false;
                }
                if (($it['sms'] ?? false) && ! $smsAvailable) {
                    return false;
                }

                return $userRank >= ($rank[$it['tier']] ?? 3);
            }));
            if ($items) {
                $out[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $out;
    }
}
