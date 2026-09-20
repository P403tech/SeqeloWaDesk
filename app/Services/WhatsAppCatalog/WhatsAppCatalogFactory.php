<?php

namespace App\Services\WhatsAppCatalog;

use App\Contracts\WhatsAppCatalogProvider;
use App\Exceptions\WhatsAppCatalogException;
use App\Models\WaCatalog;

/**
 * Resolves the right catalog provider for a workspace. Callers ask
 * the factory by workspace_id and don't care whether the workspace
 * is on Meta Cloud direct or 360dialog — same interface either way.
 *
 * Throws WhatsAppCatalogException if the workspace has no catalog
 * bound (e.g. it's a Baileys-only workspace, or the operator hasn't
 * completed the /store/catalog setup yet).
 *
 * For Baileys-only workspaces, callers should use the dedicated
 * BaileysCatalogService (Phase 4e) instead — it has a different
 * interface because Baileys can't talk to Meta's catalog.
 */
class WhatsAppCatalogFactory
{
    public static function forWorkspace(int $workspaceId): WhatsAppCatalogProvider
    {
        $catalog = WaCatalog::metaForWorkspace($workspaceId);
        if (!$catalog) {
            throw new WhatsAppCatalogException(
                'Workspace has no catalog bound. Connect a Meta Commerce Catalog at /store/catalog.'
            );
        }
        return self::forCatalog($catalog);
    }

    public static function forCatalog(WaCatalog $catalog): WhatsAppCatalogProvider
    {
        return match ($catalog->provider) {
            WaCatalog::PROVIDER_DIALOG_360 => new Dialog360CatalogProvider($catalog),
            WaCatalog::PROVIDER_META_CLOUD => new MetaCloudCatalogProvider($catalog),
            default => throw new WhatsAppCatalogException(
                'This catalog is bound to an unofficial WhatsApp number. Product cards send from Seqelo; Meta Commerce sync is not used.'
            ),
        };
    }
}
