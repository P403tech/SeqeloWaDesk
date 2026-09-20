<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-workspace binding to a Meta Commerce Catalog.
 *
 * One row per (workspace, provider). The access_token_enc field is
 * Laravel-encrypted at rest — never returned in JSON, never logged.
 */
class WaCatalog extends Model
{
    public const PROVIDER_META_CLOUD = 'meta_cloud';
    public const PROVIDER_DIALOG_360 = 'dialog_360';
    /** Unofficial / Twilio sender — products live in Seqelo, not Meta Commerce. */
    public const PROVIDER_LOCAL = 'local';

    protected $fillable = [
        'workspace_id', 'provider',
        'catalog_id', 'catalog_name',
        'waba_id', 'phone_number_id',
        'access_token_enc',
        'is_cart_enabled', 'is_catalog_visible',
        'last_synced_at', 'meta_json',
    ];

    protected $casts = [
        'access_token_enc'    => 'encrypted',
        'is_cart_enabled'     => 'boolean',
        'is_catalog_visible'  => 'boolean',
        'last_synced_at'      => 'datetime',
        'meta_json'           => 'array',
    ];

    protected $hidden = ['access_token_enc'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isMetaHosted(): bool
    {
        return in_array($this->provider, [self::PROVIDER_META_CLOUD, self::PROVIDER_DIALOG_360], true);
    }

    public static function metaForWorkspace(int $workspaceId): ?self
    {
        return self::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('provider', [self::PROVIDER_META_CLOUD, self::PROVIDER_DIALOG_360])
            ->first();
    }

    /**
     * Bind this workspace's unofficial (or Twilio) phone as a catalog the
     * flow builder can list. Meta Cloud catalogs stay on their own row.
     */
    public static function bindLocalToDevice(int $workspaceId, Device $device): self
    {
        $phone = preg_replace('/\D+/', '', (string) (($device->country_code ?? '') . ($device->phone_number ?? ''))) ?: null;

        return self::updateOrCreate(
            ['workspace_id' => $workspaceId, 'provider' => self::PROVIDER_LOCAL],
            [
                'catalog_id'          => 'local-' . $workspaceId,
                'catalog_name'        => $device->device_name ?: 'WhatsApp catalog',
                'waba_id'             => $phone,
                'phone_number_id'     => (string) $device->id,
                'is_cart_enabled'     => true,
                'is_catalog_visible'  => true,
                'meta_json'           => [
                    'engine'    => 'baileys',
                    'device_id' => $device->id,
                    'phone'     => $phone,
                ],
            ],
        );
    }

    public static function bindLocalToProviderConfig(int $workspaceId, WaProviderConfig $cfg): self
    {
        $phone = preg_replace('/\D+/', '', (string) ($cfg->phone_number ?? '')) ?: null;

        return self::updateOrCreate(
            ['workspace_id' => $workspaceId, 'provider' => self::PROVIDER_LOCAL],
            [
                'catalog_id'          => 'local-' . $workspaceId,
                'catalog_name'        => $cfg->display_label ?: (strtoupper((string) $cfg->provider) . ' catalog'),
                'waba_id'             => $phone,
                'phone_number_id'     => (string) $cfg->id,
                'is_cart_enabled'     => true,
                'is_catalog_visible'  => true,
                'meta_json'           => [
                    'engine'     => $cfg->provider,
                    'config_id'  => $cfg->id,
                    'phone'      => $phone,
                ],
            ],
        );
    }

    /**
     * If the workspace already picked a catalog phone but has no wa_catalogs
     * row, create the local row so Flows / auto-replies can see it.
     */
    public static function ensureLocalFromSender(int $workspaceId): ?self
    {
        $existing = self::query()->where('workspace_id', $workspaceId)->first();
        if ($existing) {
            return $existing;
        }

        $ws = Workspace::query()->find($workspaceId);
        $key = (string) ($ws?->catalog_sender ?? '');

        if (preg_match('/^baileys:(\d+)$/', $key, $m)) {
            $device = Device::query()->where('workspace_id', $workspaceId)->where('id', (int) $m[1])->first();
            return $device ? self::bindLocalToDevice($workspaceId, $device) : null;
        }

        if (preg_match('/^twilio:(\d+)$/', $key, $m)) {
            $cfg = WaProviderConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider', 'twilio')
                ->where('id', (int) $m[1])
                ->first();
            return $cfg ? self::bindLocalToProviderConfig($workspaceId, $cfg) : null;
        }

        $device = Device::query()
            ->where('workspace_id', $workspaceId)
            ->orderByRaw("CASE WHEN status = 'connected' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();

        return $device ? self::bindLocalToDevice($workspaceId, $device) : null;
    }

    /**
     * Base URL the catalog provider talks to.
     * Centralised so swapping API versions / hosts is one place.
     */
    public function providerBaseUrl(): string
    {
        return match ($this->provider) {
            self::PROVIDER_DIALOG_360 => 'https://waba-v2.360dialog.io',
            default                    => 'https://graph.facebook.com/v22.0',
        };
    }

    /**
     * Auth header the provider expects. 360dialog uses a custom
     * header instead of Bearer auth.
     */
    public function authHeader(): array
    {
        $token = $this->access_token_enc;
        return match ($this->provider) {
            self::PROVIDER_DIALOG_360 => ['D360-API-KEY' => $token],
            default                    => ['Authorization' => 'Bearer ' . $token],
        };
    }
}
