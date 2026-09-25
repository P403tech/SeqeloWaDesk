<?php

namespace App\Services\Ai;

use App\Models\Device;
use App\Models\FacebookPage;
use App\Models\ShopifyIntegration;
use App\Models\SystemSetting;
use App\Models\TiktokAccount;
use App\Models\WorkspaceIgAccount;
use App\Services\Instaflow\InstaflowClient;
use App\Services\Shopify\ShopifyService;
use App\Services\Tiktok\TiktokClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Live connection status for the AI agent Channels step.
 */
class AgentChannelSetup
{
    public static function snapshot(?int $wsId = null): array
    {
        try {
            $wsId = (int) ($wsId ?? (Auth::user()?->current_workspace_id ?? 0));
            $userId = (int) (Auth::id() ?? 0);

            return [
                'whatsapp'  => self::whatsapp($wsId, $userId),
                'facebook'  => self::facebook($wsId),
                'instagram' => self::instagram($wsId),
                'tiktok'    => self::tiktok($wsId),
                'shopify'   => self::shopify($wsId),
            ];
        } catch (\Throwable $e) {
            return [
                'whatsapp'  => ['platform' => true, 'connected' => false, 'accounts' => [], 'connect_url' => url('/devices'), 'hint' => ''],
                'facebook'  => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => url('/facebook/connect'), 'manual_url' => url('/facebook/connect/manual'), 'hint' => ''],
                'instagram' => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => '', 'hint' => ''],
                'tiktok'    => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => url('/tiktok/connect'), 'hint' => ''],
                'shopify'   => ['platform' => false, 'connected' => false, 'accounts' => [], 'connect_url' => url('/shopify/connect'), 'hint' => ''],
            ];
        }
    }

    private static function whatsapp(int $wsId, int $userId): array
    {
        $accounts = [];
        try {
            if ($wsId > 0 && Schema::hasTable('devices')) {
                $q = Device::query()->forWorkspace($wsId, $userId)->orderByDesc('id')->limit(12);
                foreach ($q->get() as $d) {
                    $accounts[] = [
                        'id'     => $d->id,
                        'label'  => (string) ($d->device_name ?: $d->phone_number ?: 'WhatsApp'),
                        'detail' => (string) ($d->status ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // local preview / missing schema
        }

        return [
            'platform'    => true,
            'connected'   => $accounts !== [],
            'accounts'    => $accounts,
            'connect_url' => url('/devices'),
            'hint'        => __('Cloud API numbers and QR-paired phones live here. Add a number, then turn this agent on for WhatsApp.'),
        ];
    }

    private static function facebook(int $wsId): array
    {
        $platform = (bool) SystemSetting::get('facebook_enabled', false);
        $accounts = [];
        try {
            if ($wsId > 0 && class_exists(FacebookPage::class)) {
                foreach (FacebookPage::forWorkspace($wsId)->orderBy('name')->get() as $p) {
                    $accounts[] = [
                        'id'    => $p->id,
                        'label' => (string) ($p->name ?: $p->page_id),
                        'detail'=> (string) ($p->status ?? 'connected'),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return [
            'platform'    => $platform,
            'connected'   => $accounts !== [],
            'accounts'    => $accounts,
            'connect_url' => url('/facebook/connect'),
            'manual_url'  => url('/facebook/connect/manual'),
            'hint'        => __('Log in once — every Page this Facebook account manages is added, including Messenger.'),
        ];
    }

    private static function instagram(int $wsId): array
    {
        $client = InstaflowClient::fromSettings();
        $platform = $client->isConfigured();
        $accounts = [];
        try {
            if ($wsId > 0 && class_exists(WorkspaceIgAccount::class)) {
                foreach (WorkspaceIgAccount::where('workspace_id', $wsId)->orderBy('username')->get() as $a) {
                    $accounts[] = [
                        'id'    => $a->id,
                        'label' => '@'.ltrim((string) ($a->handle() ?: $a->username ?: 'instagram'), '@'),
                        'detail'=> (string) ($a->status ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return [
            'platform'    => $platform,
            'connected'   => $accounts !== [],
            'accounts'    => $accounts,
            'connect_url' => url('/devices/instagram/connect-start'),
            'hint'        => $platform
                ? __('Connect an Instagram account. This same agent can answer DMs once the account is linked.')
                : __('Ask the platform admin to connect Instaflow under Add-ons first.'),
        ];
    }

    private static function tiktok(int $wsId): array
    {
        $platform = false;
        try {
            $platform = TiktokClient::enabled();
        } catch (\Throwable $e) {
        }
        $accounts = [];
        try {
            if ($wsId > 0 && class_exists(TiktokAccount::class)) {
                foreach (TiktokAccount::forWorkspace($wsId)->orderBy('display_name')->get() as $a) {
                    $handle = $a->username ? '@'.ltrim((string) $a->username, '@') : (string) $a->display_name;
                    $accounts[] = [
                        'id'    => $a->id,
                        'label' => $handle ?: (string) $a->open_id,
                        'detail'=> (string) ($a->status ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return [
            'platform'    => $platform,
            'connected'   => $accounts !== [],
            'accounts'    => $accounts,
            'connect_url' => url('/tiktok/connect'),
            'hint'        => $platform
                ? __('Authorize a TikTok Business account. Inbox DMs and this agent share that connection.')
                : __('Ask the platform admin to add TikTok app credentials under Settings.'),
        ];
    }

    private static function shopify(int $wsId): array
    {
        $platform = false;
        try {
            $platform = app(ShopifyService::class)->isEnabled();
        } catch (\Throwable $e) {
        }
        $accounts = [];
        try {
            if ($wsId > 0 && class_exists(ShopifyIntegration::class)) {
                foreach (ShopifyIntegration::where('workspace_id', $wsId)->orderByDesc('id')->get() as $s) {
                    $accounts[] = [
                        'id'    => $s->id,
                        'label' => (string) ($s->store_name ?: $s->store_url),
                        'detail'=> (string) ($s->store_url ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return [
            'platform'    => $platform,
            'connected'   => $accounts !== [],
            'accounts'    => $accounts,
            'connect_url' => url('/shopify/connect'),
            'hint'        => __('Shopify is a tool, not a second bot. Connect the store; this agent then reads live products and recent orders.'),
        ];
    }
}
